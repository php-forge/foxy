<?php

declare(strict_types=1);

namespace Foxy\Native;

use Composer\Semver\VersionParser;
use Foxy\Exception\{Message, RuntimeException};
use UnexpectedValueException;

use function base64_encode;
use function is_array;
use function is_string;
use function pack;
use function preg_match;

/**
 * Represents the abbreviated npm registry metadata of a package: its dist-tags and its installable versions.
 */
final readonly class PackageMetadata
{
    /**
     * @param string $name Package name.
     * @param array<string, string> $distTags Dist-tag names mapped to versions.
     * @param array<string, PackageVersion> $versions Versions keyed by version string, limited to the versions that
     * Composer's {@see VersionParser} can normalize.
     */
    public function __construct(public string $name, public array $distTags, public array $versions) {}

    /**
     * Creates the metadata from a decoded registry document.
     *
     * Versions whose key cannot be normalized by Composer's {@see VersionParser} are skipped without validation; the
     * entry's own `version` field is not consulted. `dist.integrity` is preferred; a 40-character lowercase hex
     * `dist.shasum` yields a `sha1-` integrity value instead.
     *
     * @param string $name Package name used in error messages and copied into every version.
     * @param mixed $document Registry document decoded as associative arrays.
     *
     * @throws RuntimeException if the document, its dist-tags, or a normalizable version entry is malformed.
     */
    public static function fromDocument(string $name, mixed $document): self
    {
        if (!is_array($document)) {
            throw self::invalid($name, Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED->getMessage('the document'));
        }

        $distTags = self::stringMap($name, $document, 'dist-tags', 'dist-tags');

        $entries = $document['versions'] ?? null;

        if (!is_array($entries)) {
            throw self::invalid($name, Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED->getMessage('versions'));
        }

        $parser = new VersionParser();

        $versions = [];

        foreach ($entries as $key => $entry) {
            $version = (string) $key;

            try {
                $parser->normalize($version);
            } catch (UnexpectedValueException) {
                continue;
            }

            $versions[$version] = self::parseVersion($name, $version, $entry);
        }

        return new self($name, $distTags, $versions);
    }

    /**
     * Returns the version a dist-tag points to, or `null` when the tag is unknown.
     */
    public function getDistTag(string $tag): string|null
    {
        return $this->distTags[$tag] ?? null;
    }

    /**
     * Returns the metadata of a version, or `null` when the version is unknown.
     */
    public function getVersion(string $version): PackageVersion|null
    {
        return $this->versions[$version] ?? null;
    }

    /**
     * Returns the Subresource Integrity value of a version entry's `dist` object.
     *
     * @param array<mixed> $dist
     */
    private static function integrity(string $name, string $path, array $dist): string
    {
        $integrity = $dist['integrity'] ?? null;

        if (is_string($integrity) && '' !== $integrity) {
            return $integrity;
        }

        $shasum = $dist['shasum'] ?? null;

        if (is_string($shasum) && 1 === preg_match('/^[0-9a-f]{40}$/', $shasum)) {
            return 'sha1-' . base64_encode(pack('H*', $shasum));
        }

        throw self::invalid($name, Message::NATIVE_METADATA_REASON_DIST_HASH_REQUIRED->getMessage($path));
    }

    private static function invalid(string $name, string $reason): RuntimeException
    {
        return new RuntimeException(
            Message::NATIVE_METADATA_INVALID->getMessage($name, $reason),
        );
    }

    private static function parseVersion(string $name, string $version, mixed $entry): PackageVersion
    {
        $path = "versions.{$version}";

        if (!is_array($entry)) {
            throw self::invalid($name, Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED->getMessage($path));
        }

        $dist = $entry['dist'] ?? null;

        if (!is_array($dist)) {
            throw self::invalid($name, Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED->getMessage("{$path}.dist"));
        }

        $tarball = $dist['tarball'] ?? null;

        if (!is_string($tarball) || '' === $tarball) {
            throw self::invalid(
                $name,
                Message::NATIVE_METADATA_REASON_STRING_REQUIRED->getMessage("{$path}.dist.tarball"),
            );
        }

        $integrity = self::integrity($name, $path, $dist);
        $dependencies = self::stringMap($name, $entry, 'dependencies', "{$path}.dependencies");
        $peerDependencies = self::stringMap($name, $entry, 'peerDependencies', "{$path}.peerDependencies");
        $optionalDependencies = self::stringMap($name, $entry, 'optionalDependencies', "{$path}.optionalDependencies");

        $peerDependenciesMeta = $entry['peerDependenciesMeta'] ?? [];

        if (!is_array($peerDependenciesMeta)) {
            throw self::invalid(
                $name,
                Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED->getMessage("{$path}.peerDependenciesMeta"),
            );
        }

        $optionalPeerDependencies = [];

        foreach ($peerDependenciesMeta as $peer => $meta) {
            if (true === ($meta['optional'] ?? null)) {
                $optionalPeerDependencies[] = $peer;
            }
        }

        $deprecated = $entry['deprecated'] ?? null;

        return new PackageVersion(
            $name,
            $version,
            $tarball,
            $integrity,
            $dependencies,
            $peerDependencies,
            $optionalDependencies,
            $optionalPeerDependencies,
            is_string($deprecated) && '' !== $deprecated ? $deprecated : null,
        );
    }

    /**
     * Returns the member as a map of names to strings, or `[]` when it is absent or `null`.
     *
     * @param array<mixed> $source
     *
     * @return array<string, string>
     */
    private static function stringMap(string $name, array $source, string $field, string $path): array
    {
        $map = $source[$field] ?? [];

        if (!is_array($map)) {
            throw self::invalid($name, Message::NATIVE_METADATA_REASON_STRING_MAP_REQUIRED->getMessage($path));
        }

        foreach ($map as $value) {
            if (!is_string($value)) {
                throw self::invalid($name, Message::NATIVE_METADATA_REASON_STRING_MAP_REQUIRED->getMessage($path));
            }
        }

        return $map;
    }
}
