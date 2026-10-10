<?php

declare(strict_types=1);

namespace Foxy\Native;

/**
 * Represents one published version of a registry package as read from the abbreviated npm metadata.
 */
final readonly class PackageVersion
{
    /**
     * @param string $name Package name.
     * @param string $version Version string, exactly as keyed in the registry `versions` object.
     * @param string $tarball Tarball URL (`dist.tarball`).
     * @param string $integrity Subresource Integrity value: `dist.integrity`, or `sha1-<base64>` derived from
     * `dist.shasum`.
     * @param array<string, string> $dependencies Runtime dependencies keyed by package name.
     * @param array<string, string> $peerDependencies Peer dependencies keyed by package name.
     * @param array<string, string> $optionalDependencies Optional dependencies keyed by package name.
     * @param list<string> $optionalPeerDependencies Peer names whose `peerDependenciesMeta` entry declares
     * `optional: true`.
     * @param string|null $deprecated Deprecation text, or `null` when the version is not deprecated.
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $tarball,
        public string $integrity,
        public array $dependencies = [],
        public array $peerDependencies = [],
        public array $optionalDependencies = [],
        public array $optionalPeerDependencies = [],
        public string|null $deprecated = null,
    ) {}
}
