<?php

declare(strict_types=1);

namespace Foxy\Native;

use Composer\Json\JsonFile as ComposerJsonFile;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Json\JsonFile;
use Seld\JsonLint\ParsingException;

use function count;
use function is_array;
use function is_file;
use function is_string;
use function ksort;
use function preg_match;

/**
 * Reads and writes `foxy.lock`: the requirements fingerprint and the packages installed by the native manager.
 *
 * The fingerprint maps each source (`''` for the root manifest, the local package name otherwise) to its mandatory
 * `dependencies` and its `optionalDependencies`, so moving a requirement between the two kinds makes the lock stale.
 *
 * @phpstan-type Fingerprint array<array-key, array{
 *     dependencies: array<array-key, string>,
 *     optionalDependencies: array<array-key, string>,
 * }>
 */
final readonly class LockFile
{
    private const string INTEGRITY_PATTERN = '/^(sha512|sha384|sha256|sha1)-[A-Za-z0-9+\/]+=*(\s|$)/';
    private const array README = [
        'This file locks the frontend dependencies installed by the Foxy native manager.',
        'Do not edit it manually; composer install and composer update regenerate it.',
    ];

    /**
     * @param string $path Lock file path.
     */
    public function __construct(private string $path) {}

    /**
     * Returns whether the lock file exists.
     */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Returns the requirements fingerprint and the locked packages.
     *
     * Keys are package names; PHP stores a numeric name such as `123` as an `int` key.
     *
     * @throws RuntimeException if the file is not valid JSON, does not have the lock file shape, or keys a requirement or
     * a package by an invalid package name.
     *
     * @return array{requirements: Fingerprint, packages: array<array-key, ResolvedPackage>}
     */
    public function read(): array
    {
        $data = $this->decode();

        $requirements = $data['requirements'] ?? null;

        if (!is_array($requirements)) {
            throw $this->invalid();
        }

        $fingerprint = [];

        foreach ($requirements as $source => $maps) {
            if (!is_array($maps) || 2 !== count($maps)) {
                throw $this->invalid();
            }

            $fingerprint[$source] = [
                'dependencies' => $this->specs($maps['dependencies'] ?? null),
                'optionalDependencies' => $this->specs($maps['optionalDependencies'] ?? null),
            ];
        }

        $entries = $data['packages'] ?? null;

        if (!is_array($entries)) {
            throw $this->invalid();
        }

        $packages = [];

        foreach ($entries as $name => $entry) {
            $packages[$name] = $this->package((string) $name, $entry);
        }

        return ['requirements' => $fingerprint, 'packages' => $packages];
    }

    /**
     * Writes the lock file with its maps sorted by key as strings.
     *
     * @param Fingerprint $requirements Specifications keyed by source (`''` for the root manifest, the local package
     * name otherwise), then by kind, then by package name.
     * @param array<array-key, ResolvedPackage> $packages Packages keyed by name.
     */
    public function write(array $requirements, array $packages): void
    {
        ksort($requirements, SORT_STRING);
        ksort($packages, SORT_STRING);

        $requirementMaps = [];

        foreach ($requirements as $source => $maps) {
            $requirementMaps[$source] = [
                'dependencies' => self::sorted($maps['dependencies']),
                'optionalDependencies' => self::sorted($maps['optionalDependencies']),
            ];
        }

        $entries = [];

        foreach ($packages as $name => $package) {
            $entries[$name] = $package->toLock();
        }

        (new JsonFile($this->path))->write(
            [
                '_readme' => self::README,
                'requirements' => (object) $requirementMaps,
                'packages' => (object) $entries,
            ],
        );
    }

    /**
     * Returns the decoded lock file, or `null` when it is not valid JSON.
     */
    private function decode(): mixed
    {
        try {
            return (new ComposerJsonFile($this->path))->read();
        } catch (ParsingException) {
            return null;
        }
    }

    private function invalid(): RuntimeException
    {
        return new RuntimeException(
            Message::NATIVE_LOCK_INVALID->getMessage($this->path),
        );
    }

    /**
     * Returns the package of a lock entry: `{version, file}` or `{version, resolved, integrity}`.
     *
     * @throws RuntimeException if the name is not a valid package name or the entry has neither shape.
     */
    private function package(string $name, mixed $entry): ResolvedPackage
    {
        if (!PackageName::isValid($name)) {
            throw $this->invalid();
        }

        $version = $entry['version'] ?? null;

        if (!is_string($version)) {
            throw $this->invalid();
        }

        $file = $entry['file'] ?? null;

        if (null !== $file) {
            if (!is_string($file)) {
                throw $this->invalid();
            }

            return ResolvedPackage::fromLocal($name, $version, $file);
        }

        $resolved = $entry['resolved'] ?? null;
        $integrity = $entry['integrity'] ?? null;

        if (!is_string($resolved) || !is_string($integrity) || 1 !== preg_match(self::INTEGRITY_PATTERN, $integrity)) {
            throw $this->invalid();
        }

        return new ResolvedPackage($name, $version, $resolved, $integrity);
    }

    /**
     * Returns a specification map sorted by name as strings, as an object so an empty map is written as `{}`.
     *
     * @param array<array-key, string> $specs
     */
    private static function sorted(array $specs): object
    {
        ksort($specs, SORT_STRING);

        return (object) $specs;
    }

    /**
     * Returns a specification map of the fingerprint.
     *
     * @throws RuntimeException if the value is not a map of valid package names to strings.
     *
     * @return array<array-key, string>
     */
    private function specs(mixed $specs): array
    {
        if (!is_array($specs)) {
            throw $this->invalid();
        }

        foreach ($specs as $name => $spec) {
            if (!PackageName::isValid((string) $name) || !is_string($spec)) {
                throw $this->invalid();
            }
        }

        return $specs;
    }
}
