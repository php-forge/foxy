<?php

declare(strict_types=1);

namespace Foxy\Native;

/**
 * Represents a package chosen for installation: a registry tarball or a local `file:` directory.
 */
final readonly class ResolvedPackage
{
    /**
     * @param string $name Package name.
     * @param string $version Installed version.
     * @param string|null $resolved Tarball URL of a registry package.
     * @param string|null $integrity Subresource Integrity value of a registry package.
     * @param string|null $path Local package directory: the manifest's `file:` value without the prefix, as written.
     */
    public function __construct(
        public string $name,
        public string $version,
        public string|null $resolved = null,
        public string|null $integrity = null,
        public string|null $path = null,
    ) {}

    /**
     * Creates a local package from a `file:` dependency.
     */
    public static function fromLocal(string $name, string $version, string $path): self
    {
        return new self($name, $version, path: $path);
    }

    /**
     * Creates a registry package from the selected version's tarball URL and integrity value.
     */
    public static function fromRegistry(PackageVersion $version): self
    {
        return new self($version->name, $version->version, $version->tarball, $version->integrity);
    }

    /**
     * Returns whether the package is copied from a local directory instead of downloaded from the registry.
     */
    public function isLocal(): bool
    {
        return null !== $this->path;
    }

    /**
     * Returns the package entry written to `foxy.lock`.
     *
     * @return array{version: string, file: string}|array{version: string, resolved: string, integrity: string}
     */
    public function toLock(): array
    {
        if ($this->isLocal()) {
            return ['version' => $this->version, 'file' => $this->path];
        }

        return ['version' => $this->version, 'resolved' => $this->resolved, 'integrity' => $this->integrity];
    }
}
