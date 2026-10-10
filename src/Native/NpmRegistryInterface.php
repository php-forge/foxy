<?php

declare(strict_types=1);

namespace Foxy\Native;

use Foxy\Exception\RuntimeException;

/**
 * Provides access to an npm registry: package metadata, verified tarballs, and security advisories.
 */
interface NpmRegistryInterface
{
    /**
     * Returns the path of a verified `.tgz` written inside the directory.
     *
     * @param ResolvedPackage $package Registry package whose tarball URL and integrity are used.
     * @param string $directory Existing directory that receives the tarball.
     *
     * @throws RuntimeException if the request fails or the tarball does not match its integrity value.
     */
    public function fetchTarball(ResolvedPackage $package, string $directory): string;

    /**
     * Returns the registry's bulk advisories document for the given versions, as the registry wrote it.
     *
     * @param array<string, non-empty-list<string>> $versions Locked versions keyed by package name.
     *
     * @throws RuntimeException if the request fails.
     */
    public function getAdvisories(array $versions): string;

    /**
     * Returns the abbreviated metadata of a package.
     *
     * @param string $name Package name, scoped names included (`@scope/name`).
     *
     * @throws RuntimeException if the package is unknown, the request fails, or the metadata is malformed.
     */
    public function getMetadata(string $name): PackageMetadata;

    /**
     * Downloads the tarballs missing from the cache in parallel, verifying each before it is cached.
     *
     * The prefetch is best-effort: a tarball that fails to download or to verify is left out of the cache, and the
     * later {@see fetchTarball()} downloads it again and reports the error.
     *
     * @param list<ResolvedPackage> $packages Registry packages whose tarballs are wanted.
     */
    public function prefetchTarballs(array $packages): void;
}
