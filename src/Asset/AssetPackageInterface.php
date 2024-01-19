<?php

declare(strict_types=1);

/*
 * This file is part of the Foxy package.
 *
 * (c) François Pluchino <francois.pluchino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Foxy\Asset;

/**
 * Interface of asset package.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
interface AssetPackageInterface
{
    /**
     * Write the asset package in file.
     */
    public function write(): self;

    /**
     * Set the asset package.
     *
     * @param array $package The asset package
     */
    public function setPackage(array $package): self;

    /**
     * Get the asset package.
     */
    public function getPackage(): array;

    /**
     * Get the installed asset dependencies.
     *
     * @return array The installed asset dependencies
     */
    public function getInstalledDependencies(): array;

    /**
     * Add the new asset dependencies and return the names of already installed asset dependencies.
     *
     * @param array $dependencies The asset dependencies
     *
     * @return array The asset package name of the already asset dependencies
     */
    public function addNewDependencies(array $dependencies): array;

    /**
     * Remove the unused asset dependencies.
     *
     * @param array $dependencies All asset dependencies
     */
    public function removeUnusedDependencies(array $dependencies): self;
}
