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

use Composer\Json\JsonFile;
use Composer\Package\RootPackageInterface;

/**
 * Asset package.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class AssetPackage implements AssetPackageInterface
{
    public const SECTION_DEPENDENCIES = 'dependencies';
    public const SECTION_DEV_DEPENDENCIES = 'devDependencies';
    public const COMPOSER_PREFIX = '@composer-asset/';
    protected array $package = [];

    /**
     * @param RootPackageInterface $rootPackage The composer root package
     * @param JsonFile $jsonFile    The json file
     */
    public function __construct(RootPackageInterface $rootPackage, protected JsonFile $jsonFile)
    {
        $this->jsonFile = $jsonFile;

        if ($jsonFile->exists()) {
            $this->setPackage((array) $jsonFile->read());
        }

        $this->injectRequiredKeys($rootPackage);
    }

    public function write(): self
    {
        $this->jsonFile->write($this->package);

        return $this;
    }

    public function setPackage(array $package): self
    {
        $this->package = $package;

        return $this;
    }

    public function getPackage(): array
    {
        return $this->package;
    }

    public function getInstalledDependencies(): array
    {
        $installedAssets = [];

        if (isset($this->package[self::SECTION_DEPENDENCIES]) && \is_array($this->package[self::SECTION_DEPENDENCIES])) {
            /**
             * @var string $dependency
             * @var string $version
             */
            foreach ($this->package[self::SECTION_DEPENDENCIES] as $dependency => $version) {
                if (str_starts_with($dependency, self::COMPOSER_PREFIX)) {
                    $installedAssets[$dependency] = $version;
                }
            }
        }

        return $installedAssets;
    }

    /**
     * Add new dependencies.
     *
     * @param array $dependencies The dependencies
     *
     * @return array The existing packages.
     *
     * @psalm-return list<string> The existing packages.
     *
     * @psalm-suppress MixedArrayAssignment
     */
    public function addNewDependencies(array $dependencies): array
    {
        $installedAssets = $this->getInstalledDependencies();
        $existingPackages = [];

        /**
         * @var string $name
         * @var string $path
         */
        foreach ($dependencies as $name => $path) {
            if (isset($installedAssets[$name])) {
                $existingPackages[] = $name;
            } else {
                $this->package[self::SECTION_DEPENDENCIES][$name] = 'file:./' . \dirname($path);
            }
        }

        $this->orderPackages(self::SECTION_DEPENDENCIES);
        $this->orderPackages(self::SECTION_DEV_DEPENDENCIES);

        return $existingPackages;
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function removeUnusedDependencies(array $dependencies): self
    {
        $installedAssets = $this->getInstalledDependencies();
        $removeDependencies = array_diff_key($installedAssets, $dependencies);

        foreach ($removeDependencies as $dependency => $version) {
            unset($this->package[self::SECTION_DEPENDENCIES][$dependency]);
        }

        return $this;
    }

    /**
     * Inject the required keys for asset package defined in root composer package.
     *
     * @param RootPackageInterface $rootPackage The composer root package
     */
    protected function injectRequiredKeys(RootPackageInterface $rootPackage): void
    {
        if (!isset($this->package['license']) && \count($rootPackage->getLicense()) > 0) {
            $license = current($rootPackage->getLicense());

            if ('proprietary' === $license) {
                if (!isset($this->package['private'])) {
                    $this->package['private'] = true;
                }
            } else {
                $this->package['license'] = $license;
            }
        }
    }

    /**
     * Order the packages section.
     *
     * @param string $section The package section
     */
    protected function orderPackages(string $section): void
    {
        if (isset($this->package[$section]) && \is_array($this->package[$section])) {
            ksort($this->package[$section], SORT_STRING);
        }
    }
}
