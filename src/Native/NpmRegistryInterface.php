<?php

declare(strict_types=1);

namespace Foxy\Native;

use Foxy\Exception\RuntimeException;

/**
 * Provides access to an npm registry: package metadata and verified tarballs.
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
     * Returns the abbreviated metadata of a package.
     *
     * @param string $name Package name, scoped names included (`@scope/name`).
     *
     * @throws RuntimeException if the package is unknown, the request fails, or the metadata is malformed.
     */
    public function getMetadata(string $name): PackageMetadata;
}
