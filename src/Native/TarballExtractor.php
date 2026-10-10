<?php

declare(strict_types=1);

namespace Foxy\Native;

use Throwable;
use Composer\Util\Filesystem;
use Foxy\Exception\{Message, RuntimeException};

use function array_map;
use function array_slice;
use function array_sum;
use function ceil;
use function chmod;
use function dirname;
use function explode;
use function file_put_contents;
use function fopen;
use function implode;
use function in_array;
use function intval;
use function preg_match;
use function str_repeat;
use function str_replace;
use function str_split;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function substr;
use function substr_replace;

/**
 * Extracts an npm package tarball (gzip-compressed or plain tar) into a directory, stripping the first path component.
 *
 * Reads POSIX ustar, PAX extended headers (`path` and `size` records) and GNU long names. Directories and regular
 * files are created; symbolic links, hard links and unknown entry types are skipped. A file whose mode carries an
 * execute bit is made executable (`0755`); modification times are not preserved.
 *
 * @see https://pubs.opengroup.org/onlinepubs/9799919799/utilities/pax.html
 */
final readonly class TarballExtractor
{
    /**
     * Size of a tar block: every header is one block and entry data is padded to whole blocks.
     */
    private const int BLOCK = 512;

    /**
     * Typeflags of regular files: `0`, the pre-POSIX NUL flag and contiguous files.
     */
    private const array FILE_TYPES = ['', '0', '7'];

    /**
     * Widths of the header fields that precede the ustar `prefix` field, in header order.
     */
    private const array LAYOUT = [
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
    ];

    /**
     * Magic values of POSIX ustar (`ustar\0`) and GNU (`ustar `) headers.
     */
    private const array MAGICS = ["ustar\0", 'ustar '];

    /**
     * @param Filesystem $fs Filesystem used to create directories.
     */
    public function __construct(private Filesystem $fs) {}

    /**
     * Extracts the tarball into the destination directory, creating it first.
     *
     * @param string $tarball Path of the `.tgz` (or plain `.tar`) file.
     * @param string $destination Directory that receives the package contents.
     * @param string $label Name of the tarball used in error messages, the tarball URL in production.
     *
     * @throws RuntimeException if the tarball cannot be opened, is not a valid ustar archive, is truncated, holds an
     * entry outside the package directory, or an entry cannot be written.
     */
    public function extract(string $tarball, string $destination, string $label): void
    {
        $stream = @fopen("compress.zlib://{$tarball}", 'rb');

        if (false === $stream) {
            throw self::invalid($label, Message::NATIVE_TARBALL_REASON_UNREADABLE->getMessage());
        }

        $this->fs->ensureDirectoryExists($destination);

        $overrides = [];

        while (null !== ($header = $this->parseHeader($this->readHeader($stream, $label), $label))) {
            if ('x' === $header['type'] || 'L' === $header['type']) {
                $data = $this->readData($stream, $header['size'], $label);
                $records = 'x' === $header['type'] ? $this->paxRecords($data, $label) : ['path' => self::cString($data)];
                $overrides = $records + $overrides;

                continue;
            }

            $name = $overrides['path'] ?? $header['name'];
            $size = (int) ($overrides['size'] ?? $header['size']);
            $overrides = [];

            $this->extractEntry($header, $name, $this->readData($stream, $size, $label), $destination, $label);
        }
    }

    /**
     * Returns whether the stored checksum equals the sum of the header bytes with the checksum field read as spaces.
     */
    private function checksumIsValid(string $header, int $checksum): bool
    {
        return $checksum === array_sum(array_map(ord(...), str_split(substr_replace($header, '        ', 148, 8))));
    }

    /**
     * Creates the directory of an entry.
     *
     * @throws RuntimeException if the directory cannot be created.
     */
    private function createDirectory(string $path, string $name, string $label): void
    {
        try {
            $this->fs->ensureDirectoryExists($path);
        } catch (\RuntimeException $exception) {
            throw self::invalid($label, Message::NATIVE_TARBALL_REASON_WRITE_FAILED->getMessage($name), $exception);
        }
    }

    /**
     * Returns the leading NUL-terminated string of a field.
     */
    private static function cString(string $value): string
    {
        return explode("\0", $value)[0];
    }

    /**
     * Creates a directory or writes a regular file for an entry; other entry types are skipped.
     *
     * @param array{name: string, size: int, type: string, mode: int, link: string} $header Parsed entry header.
     * @param string $name Entry name after PAX and GNU long-name overrides.
     * @param string $data Entry data.
     */
    private function extractEntry(array $header, string $name, string $data, string $destination, string $label): void
    {
        $isFile = in_array($header['type'], self::FILE_TYPES, true);

        if (!$isFile && '5' !== $header['type']) {
            return;
        }

        $path = $this->targetPath($name, $destination, $label);

        if (null === $path) {
            return;
        }

        if ($isFile) {
            $this->writeFile($path, $data, $header['mode'], $name, $label);

            return;
        }

        $this->createDirectory($path, $name, $label);
    }

    /**
     * Creates the exception for an archive that cannot be extracted.
     */
    private static function invalid(string $label, string $reason, Throwable|null $previous = null): RuntimeException
    {
        return new RuntimeException(
            Message::NATIVE_TARBALL_INVALID->getMessage($label, $reason),
            previous: $previous,
        );
    }

    /**
     * Returns the value of an octal number field, which may be padded with spaces.
     *
     * @throws RuntimeException if the field holds anything other than octal digits.
     */
    private function octal(string $field, string $label): int
    {
        $value = self::cString($field);

        if (1 !== preg_match('/^ *[0-7]* *$/', $value)) {
            throw self::invalid($label, Message::NATIVE_TARBALL_REASON_NOT_USTAR->getMessage());
        }

        return intval($value, 8);
    }

    /**
     * Parses a header block.
     *
     * The ustar `prefix` field is prepended to the name only for POSIX headers; GNU headers use that area otherwise.
     *
     * @return array{name: string, size: int, type: string, mode: int, link: string}|null The entry header, or `null`
     * for the end-of-archive block.
     *
     * @throws RuntimeException if the magic, the checksum or a number field is invalid.
     */
    private function parseHeader(string $header, string $label): array|null
    {
        if (str_repeat("\0", self::BLOCK) === $header) {
            return null;
        }

        $fields = [];
        $offset = 0;

        foreach (self::LAYOUT as $field => $width) {
            $fields[$field] = substr($header, $offset, $width);
            $offset += $width;
        }

        if (
            !in_array($fields['magic'], self::MAGICS, true)
            || !$this->checksumIsValid($header, $this->octal($fields['checksum'], $label))
        ) {
            throw self::invalid($label, Message::NATIVE_TARBALL_REASON_NOT_USTAR->getMessage());
        }

        $name = self::cString($fields['name']);
        $prefix = self::cString(substr($header, $offset));

        if ("ustar\0" === $fields['magic'] && '' !== $prefix) {
            $name = "{$prefix}/{$name}";
        }

        return [
            'name' => $name,
            'size' => $this->octal($fields['size'], $label),
            'type' => self::cString($fields['type']),
            'mode' => $this->octal($fields['mode'], $label),
            'link' => self::cString($fields['link']),
        ];
    }

    /**
     * Parses the `<length> <key>=<value>\n` records of a PAX extended header.
     *
     * @return array<string, string> Record values keyed by record name.
     *
     * @throws RuntimeException if a record is malformed.
     */
    private function paxRecords(string $data, string $label): array
    {
        $records = [];

        while ('' !== $data) {
            if (1 !== preg_match('/^\d+ ([^=]+)=/', $data, $match)) {
                throw self::invalid($label, Message::NATIVE_TARBALL_REASON_NOT_USTAR->getMessage());
            }

            $length = (int) $data;

            if ($length <= strlen($match[0])) {
                throw self::invalid($label, Message::NATIVE_TARBALL_REASON_NOT_USTAR->getMessage());
            }

            $records[$match[1]] = substr($data, strlen($match[0]), $length - strlen($match[0]) - 1);
            $data = substr($data, $length);
        }

        return $records;
    }

    /**
     * Reads exactly `$length` bytes.
     *
     * @param resource $stream Tarball stream.
     *
     * @throws RuntimeException if the stream ends first.
     */
    private function read($stream, int $length, string $label): string
    {
        $bytes = stream_get_contents($stream, $length);

        if (false === $bytes || $length !== strlen($bytes)) {
            throw self::invalid($label, Message::NATIVE_TARBALL_REASON_TRUNCATED->getMessage());
        }

        return $bytes;
    }

    /**
     * Reads the data blocks of an entry and returns its `$size` data bytes without the block padding.
     *
     * @param resource $stream Tarball stream.
     *
     * @throws RuntimeException if the stream ends inside the data blocks.
     */
    private function readData($stream, int $size, string $label): string
    {
        return substr($this->read($stream, (int) ceil($size / self::BLOCK) * self::BLOCK, $label), 0, $size);
    }

    /**
     * Reads a header block.
     *
     * @param resource $stream Tarball stream.
     *
     * @throws RuntimeException if the stream ends inside the block.
     */
    private function readHeader($stream, string $label): string
    {
        return $this->read($stream, self::BLOCK, $label);
    }

    /**
     * Returns the path an entry is extracted to, with its first path component replaced by the destination.
     *
     * Backslashes count as path separators, so a name cannot escape the destination through the Windows separator.
     *
     * @return string|null The target path, or `null` when nothing remains after the first component.
     *
     * @throws RuntimeException if the name is absolute or holds a `..` segment.
     */
    private function targetPath(string $name, string $destination, string $label): string|null
    {
        $normalized = str_replace('\\', '/', $name);
        $segments = explode('/', $normalized);

        if (str_starts_with($normalized, '/') || in_array('..', $segments, true)) {
            throw self::invalid($label, Message::NATIVE_TARBALL_REASON_ENTRY_ESCAPES->getMessage($name));
        }

        $relative = implode('/', array_slice($segments, 1));

        return '' === $relative ? null : "{$destination}/{$relative}";
    }

    /**
     * Writes a regular file, creating its parent directories, and makes it executable when the mode asks for it.
     *
     * @throws RuntimeException if the file or its directory cannot be written.
     */
    private function writeFile(string $path, string $data, int $mode, string $name, string $label): void
    {
        $this->createDirectory(dirname($path), $name, $label);

        if (false === @file_put_contents($path, $data)) {
            throw self::invalid($label, Message::NATIVE_TARBALL_REASON_WRITE_FAILED->getMessage($name));
        }

        if (0 !== ($mode & 0o111)) {
            chmod($path, 0o755);
        }
    }
}
