<?php

declare(strict_types=1);

namespace Foxy\Native;

use Composer\IO\IOInterface;
use Composer\Json\JsonFile as ComposerJsonFile;
use Foxy\Json\JsonFile;
use Seld\JsonLint\ParsingException;

use function array_keys;
use function is_array;
use function is_file;
use function is_string;
use function ksort;
use function sprintf;

use const SORT_STRING;

/**
 * Reads and writes the hidden state file of an install directory: the lock entry of every package the last successful
 * native installation left in place, the counterpart of npm's `node_modules/.package-lock.json`.
 *
 * The state is a cache: a missing or malformed file reads as empty, so the installer extracts every registry package
 * again.
 */
final readonly class InstalledState
{
    private const string MALFORMED = '<warning>The installed state file "%s" is malformed and was ignored.</warning>';

    /**
     * Creates an installed-state store for one native install directory.
     *
     * @param string $path State file path.
     * @param IOInterface $io Output for the verbose notice of a malformed file.
     */
    public function __construct(private string $path, private IOInterface $io) {}

    /**
     * Returns the recorded lock entries keyed by package name, in the shape of {@see ResolvedPackage::toLock()}.
     *
     * A missing file reads as empty. A file that is not valid JSON, has no `packages` map, or holds an entry of
     * another shape also reads as empty, with a verbose warning.
     *
     * Keys are package names; PHP stores a numeric name such as `123` as an `int` key.
     *
     * @return array<array-key, array<string, string>>
     */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $entries = $this->decode()['packages'] ?? null;

        if (!is_array($entries)) {
            return $this->malformed();
        }

        $state = [];

        foreach ($entries as $name => $entry) {
            if (!self::isEntry($entry)) {
                return $this->malformed();
            }

            $state[$name] = $entry;
        }

        return $state;
    }

    /**
     * Writes the state file as `{"packages": {...}}`, with the names sorted byte-wise.
     *
     * @param array<array-key, array<string, string>> $entries Lock entries keyed by package name.
     */
    public function write(array $entries): void
    {
        ksort($entries, SORT_STRING);

        (new JsonFile($this->path))->write(['packages' => (object) $entries]);
    }

    /**
     * Returns the decoded state file, or `null` when it is not valid JSON.
     */
    private function decode(): mixed
    {
        try {
            return (new ComposerJsonFile($this->path))->read();
        } catch (ParsingException) {
            return null;
        }
    }

    /**
     * Returns whether a value is a lock entry: string `version`, `resolved`, and `integrity`, or `version` and `file`.
     *
     * @phpstan-assert-if-true array<string, string> $entry
     */
    private static function isEntry(mixed $entry): bool
    {
        if (!is_array($entry)) {
            return false;
        }

        $keys = array_keys($entry);

        if ($keys !== ['version', 'resolved', 'integrity'] && $keys !== ['version', 'file']) {
            return false;
        }

        foreach ($entry as $value) {
            if (!is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Writes the verbose warning of a malformed file and returns the empty state.
     *
     * @return array{}
     */
    private function malformed(): array
    {
        $this->io->writeError(sprintf(self::MALFORMED, $this->path), true, IOInterface::VERBOSE);

        return [];
    }
}
