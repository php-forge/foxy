<?php

declare(strict_types=1);

namespace Foxy\Tests\Fixtures\Native;

use Foxy\Exception\{Message, RuntimeException};
use Foxy\Native\{NpmRegistry, NpmRegistryInterface, PackageMetadata, ResolvedPackage};

use function file_put_contents;
use function str_replace;

/**
 * Provides an {@see NpmRegistryInterface} backed by in-memory metadata and tarball bytes that records every request.
 */
final class InMemoryRegistry implements NpmRegistryInterface
{
    /**
     * @var list<string> Tarball URLs passed to {@see fetchTarball()}, in request order.
     */
    public array $fetched = [];

    /**
     * @var list<string> Names passed to {@see getMetadata()}, in request order.
     */
    public array $requested = [];

    /**
     * @var array<string, string> Tarball bytes keyed by tarball URL.
     */
    private array $tarballs = [];

    /**
     * @param array<string, PackageMetadata> $packages Metadata keyed by package name.
     */
    public function __construct(private array $packages = []) {}

    /**
     * Registers the metadata of a package, replacing any previous entry with the same name.
     */
    public function add(PackageMetadata $metadata): self
    {
        $this->packages[$metadata->name] = $metadata;

        return $this;
    }

    /**
     * Registers the bytes served for a tarball URL, replacing any previous entry with the same URL.
     */
    public function addTarball(string $resolved, string $bytes): self
    {
        $this->tarballs[$resolved] = $bytes;

        return $this;
    }

    /**
     * Writes the registered bytes of the package's tarball URL into the directory, named as {@see NpmRegistry} does.
     *
     * @throws RuntimeException if no bytes are registered for the URL.
     */
    public function fetchTarball(ResolvedPackage $package, string $directory): string
    {
        $resolved = (string) $package->resolved;
        $this->fetched[] = $resolved;

        $bytes = $this->tarballs[$resolved]
            ?? throw new RuntimeException(Message::NATIVE_REGISTRY_REQUEST_FAILED->getMessage($resolved, 'not registered'));

        $file = "{$directory}/" . str_replace(['@', '/'], ['', '-'], $package->name) . "-{$package->version}.tgz";

        file_put_contents($file, $bytes);

        return $file;
    }

    public function getMetadata(string $name): PackageMetadata
    {
        $this->requested[] = $name;

        return $this->packages[$name]
            ?? throw new RuntimeException(Message::NATIVE_PACKAGE_NOT_FOUND->getMessage($name, 'memory'));
    }
}
