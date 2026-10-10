<?php

declare(strict_types=1);

namespace Foxy\Tests\Support;

use LogicException;

use function array_map;
use function array_sum;
use function ceil;
use function gzencode;
use function implode;
use function sprintf;
use function str_pad;
use function str_repeat;
use function str_split;
use function strlen;
use function strrpos;
use function substr;

/**
 * Builds tar archives in memory so tests never depend on the `tar` binary.
 *
 * Entries are POSIX ustar headers by default; a name longer than 100 bytes is split into the `prefix` and `name`
 * fields. {@see addEntry()} overrides any raw header field (`magic`, `checksum`, `size`, `prefix`, ...) to produce
 * malformed or GNU-style headers. `size` and `mtime` are space-terminated; `checksum` is seven octal digits and NUL in
 * POSIX headers and GNU tar's six digits, NUL and space in headers with the GNU magic (`ustar `).;
 * ```
 */
final class TarArchive
{
    /**
     * Widths of the ustar header fields, in header order.
     */
    private const array FIELDS = [
        'name' => 100,
        'mode' => 8,
        'uid' => 8,
        'gid' => 8,
        'size' => 12,
        'mtime' => 12,
        'checksum' => 8,
        'type' => 1,
        'link' => 100,
        'magic' => 6,
        'version' => 2,
        'uname' => 32,
        'gname' => 32,
        'devmajor' => 8,
        'devminor' => 8,
        'prefix' => 155,
        'padding' => 12,
    ];

    /**
     * @var list<string> Header and data blocks in archive order.
     */
    private array $blocks = [];

    /**
     * Adds a directory entry (typeflag `5`).
     */
    public function addDirectory(string $name, int $mode = 0o755): self
    {
        return $this->addEntry('5', $name, '', $mode);
    }

    /**
     * Adds an entry with raw header field overrides.
     *
     * @param string $type Typeflag (`0`, `\0`, `5`, `x`, ...).
     * @param string $data Entry data, padded to whole blocks; the `size` field is its length unless overridden.
     * @param array<string, string> $fields Raw header field values keyed by the names of {@see self::FIELDS}; the
     * checksum is computed after the overrides unless `checksum` is overridden too.
     */
    public function addEntry(string $type, string $name, string $data = '', int $mode = 0o644, array $fields = []): self
    {
        $prefix = '';

        if (strlen($name) > 100) {
            $split = (int) strrpos(substr($name, 0, 156), '/');
            $prefix = substr($name, 0, $split);
            $name = substr($name, $split + 1);
        }

        $values = $fields + [
            'name' => $name,
            'mode' => sprintf('%07o', $mode) . "\0",
            'uid' => "0000000\0",
            'gid' => "0000000\0",
            'size' => sprintf('%011o ', strlen($data)),
            'mtime' => '00000000000 ',
            'type' => $type,
            'link' => '',
            'magic' => "ustar\0",
            'version' => '00',
            'prefix' => $prefix,
        ];

        $header = '';

        foreach (self::FIELDS as $field => $width) {
            $value = 'checksum' === $field ? '        ' : ($values[$field] ?? '');

            if (strlen($value) > $width) {
                throw new LogicException(sprintf('The "%s" field is longer than %d bytes.', $field, $width));
            }

            $header .= str_pad($value, $width, "\0");
        }

        $sum = array_sum(array_map(ord(...), str_split($header)));
        $checksum = $fields['checksum'] ?? sprintf('ustar ' === $values['magic'] ? "%06o\0 " : "%07o\0", $sum);
        $header = substr($header, 0, 148) . str_pad($checksum, 8, "\0") . substr($header, 156);

        $this->blocks[] = $header;
        $this->blocks[] = str_pad($data, (int) ceil(strlen($data) / 512) * 512, "\0");

        return $this;
    }

    /**
     * Adds a regular file entry.
     *
     * @param string $type Typeflag of the file: `0`, `\0` or `7`.
     */
    public function addFile(string $name, string $contents, int $mode = 0o644, string $type = '0'): self
    {
        return $this->addEntry($type, $name, $contents, $mode);
    }

    /**
     * Adds a hard-link entry (typeflag `1`).
     */
    public function addHardLink(string $name, string $target): self
    {
        return $this->addEntry('1', $name, fields: ['link' => $target]);
    }

    /**
     * Adds a GNU long-name entry (typeflag `L`, name `././@LongLink`) followed by a GNU regular file whose own name
     * field holds the first 100 bytes of the name.
     */
    public function addLongName(string $name, string $contents, int $mode = 0o644): self
    {
        $gnu = ['magic' => 'ustar ', 'version' => " \0"];

        $this->addEntry('L', '././@LongLink', "{$name}\0", fields: $gnu);

        return $this->addEntry('0', substr($name, 0, 100), $contents, $mode, $gnu + ['name' => substr($name, 0, 100)]);
    }

    /**
     * Adds a PAX extended header (typeflag `x`) or a global one (typeflag `g`) holding the given records.
     *
     * @param array<string, string> $records Record values keyed by record name (`path`, `size`, ...).
     */
    public function addPax(array $records, bool $global = false): self
    {
        $data = '';

        foreach ($records as $key => $value) {
            $data .= self::paxRecord($key, $value);
        }

        return $this->addEntry($global ? 'g' : 'x', $global ? 'pax_global_header' : 'PaxHeader/entry', $data);
    }

    /**
     * Adds a symbolic-link entry (typeflag `2`).
     */
    public function addSymlink(string $name, string $target): self
    {
        return $this->addEntry('2', $name, fields: ['link' => $target]);
    }

    /**
     * Returns the uncompressed tar bytes, terminated by two end-of-archive blocks.
     */
    public function bytes(): string
    {
        return implode('', $this->blocks) . str_repeat("\0", 1024);
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * Returns the gzip-compressed tar bytes.
     */
    public function gzip(): string
    {
        return self::compress($this->bytes());
    }

    /**
     * Returns the first `$length` tar bytes, gzip-compressed, simulating an archive cut short.
     */
    public function truncated(int $length): string
    {
        return self::compress(substr($this->bytes(), 0, $length));
    }

    private static function compress(string $bytes): string
    {
        $compressed = gzencode($bytes);

        if (false === $compressed) {
            throw new LogicException('Unable to gzip the tar archive.');
        }

        return $compressed;
    }

    /**
     * Returns a `<length> <key>=<value>\n` record whose length counts its own digits.
     */
    private static function paxRecord(string $key, string $value): string
    {
        $body = " {$key}={$value}\n";
        $length = strlen($body);

        while (strlen($body) + strlen((string) $length) !== $length) {
            $length = strlen($body) + strlen((string) $length);
        }

        return "{$length}{$body}";
    }
}
