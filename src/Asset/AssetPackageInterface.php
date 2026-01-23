<?php

declare(strict_types=1);

namespace Foxy\Asset;

interface AssetPackageInterface
{
    /**
     * Add the new asset dependencies and return the names of already installed asset dependencies.
     *
     * @param array $dependencies The asset dependencies
     *
     * @return array The asset package name of the already asset dependencies
     */
    public function addNewDependencies(array $dependencies): array;

    /**
     * Get the installed asset dependencies.
     *
     * @return array The installed asset dependencies
     */
    public function getInstalledDependencies(): array;

    /**
     * Get the asset package.
     */
    public function getPackage(): array;

    /**
     * Remove the unused asset dependencies.
     *
     * @param array $dependencies All asset dependencies
     */
    public function removeUnusedDependencies(array $dependencies): self;

    /**
     * Set the asset package.
     *
     * @param array $package The asset package
     */
    public function setPackage(array $package): self;

    /**
     * Write the asset package in file.
     */
    public function write(): self;
}
