<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\Package\RootPackageInterface;
use Foxy\Exception\RuntimeException;
use Foxy\Fallback\FallbackInterface;

interface AssetManagerInterface
{
    /**
     * Add the asset dependencies in asset package file.
     *
     * @param RootPackageInterface $rootPackage  The composer root package
     * @param array $dependencies The asset local dependencies
     */
    public function addDependencies(RootPackageInterface $rootPackage, array $dependencies): AssetPackageInterface;

    /**
     * Get the filename of the lock file.
     */
    public function getLockPackageName(): string;

    /**
     * Get the name of asset manager.
     */
    public function getName(): string;

    /**
     * Get the filename of the asset package.
     */
    public function getPackageName(): string;

    /**
     * Check if the lock file is present or not.
     */
    public function hasLockFile(): bool;

    /**
     * Check if the asset manager is available.
     */
    public function isAvailable(): bool;

    /**
     * Check if the asset dependencies are installed or not.
     */
    public function isInstalled(): bool;

    /**
     * Check if the asset manager can be use the update command or not.
     */
    public function isUpdatable(): bool;

    /**
     * Check if the asset package is valid for the update.
     */
    public function isValidForUpdate(): bool;

    /**
     * Run the asset manager to install/update the asset dependencies.
     */
    public function run(): int;

    /**
     * Set the fallback.
     *
     * @param FallbackInterface $fallback The fallback
     */
    public function setFallback(FallbackInterface $fallback): self;

    /**
     * Define if the asset manager can be use the update command.
     *
     * @param bool $updatable The value
     */
    public function setUpdatable(bool $updatable): self;

    /**
     * Validate the version of asset manager.
     *
     * @throws RuntimeException When the binary isn't installed
     * @throws RuntimeException When the version doesn't match
     */
    public function validate(): void;
}
