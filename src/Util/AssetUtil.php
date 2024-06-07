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

namespace Foxy\Util;

use Composer\Installer\InstallationManager;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Foxy\Asset\AssetManagerInterface;
use Foxy\Asset\AssetPackage;

/**
 * Helper for Foxy.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class AssetUtil
{
    /**
     * Get the name for the asset dependency.
     *
     * @param PackageInterface $package The package instance.
     */
    public static function getName(PackageInterface $package): string
    {
        return AssetPackage::COMPOSER_PREFIX . \str_replace(['/'], '--', $package->getName());
    }

    /**
     * Get the path of asset file.
     *
     * @param InstallationManager $installationManager The installation manager.
     * @param AssetManagerInterface $assetManager The asset manager.
     * @param PackageInterface $package The package instance.
     * @param array $configPackages The packages defined in config.
     */
    public static function getPath(
        InstallationManager $installationManager,
        AssetManagerInterface $assetManager,
        PackageInterface $package,
        array $configPackages = []
    ): string|null {
        $path = null;


        if (self::isAsset($package, $configPackages)) {
            $composerJsonPath = null;
            $installPath = $installationManager->getInstallPath($package);

            if (null !== $installPath) {
                $composerJsonPath = $installPath . '/composer.json';
            }

            if (null !== $composerJsonPath && \file_exists($composerJsonPath)) {
                /** @var array[] $composerJson */
                $composerJson = \json_decode(\file_get_contents($composerJsonPath), true);
                $rootPackageDir = $composerJson['config']['foxy']['root-package-json-dir'] ?? null;

                if (null !== $installPath && \is_string($rootPackageDir)) {
                    $installPath .= '/' . $rootPackageDir;
                }
            }


            if (null !== $installPath) {
                $filename = $installPath . '/' . $assetManager->getPackageName();
                $path = \file_exists($filename) ? \str_replace('\\', '/', \realpath($filename)) : null;
            }
        }

        return $path;
    }

    /**
     * Check if the package is available for Foxy.
     *
     * @param PackageInterface $package The package instance.
     * @param array $configPackages The packages defined in config.
     */
    public static function isAsset(PackageInterface $package, array $configPackages = []): bool
    {
        $projectConfig = self::getProjectActivation($package, $configPackages);
        $enabled = false !== $projectConfig;

        return $enabled && (self::hasExtraActivation($package)
            || self::hasPluginDependency($package->getRequires())
            || self::hasPluginDependency($package->getDevRequires())
            || true === $projectConfig);
    }

    /**
     * Check if foxy is enabled in extra section of package.
     *
     * @param PackageInterface $package The package instance.
     */
    public static function hasExtraActivation(PackageInterface $package): bool
    {
        $extra = $package->getExtra();

        return isset($extra['foxy']) && true === $extra['foxy'];
    }

    /**
     * Check if the package contains assets.
     *
     * @param Link[] $requires The require links.
     *
     * @psalm-param Link[] $requires The require links.
     */
    public static function hasPluginDependency(array $requires): bool
    {
        $assets = false;

        foreach ($requires as $require) {
            if ('php-forge/foxy' === $require->getTarget()) {
                $assets = true;

                break;
            }
        }

        return $assets;
    }

    /**
     * Check if the package is enabled by the project config.
     *
     * @param PackageInterface $package The package instance.
     * @param array $configPackages The packages defined in config.
     */
    public static function isProjectActivation(PackageInterface $package, array $configPackages): bool
    {
        return true === self::getProjectActivation($package, $configPackages);
    }

    /**
     * Format the asset package.
     *
     * @param PackageInterface $package The composer package instance.
     * @param string $packageName  The package name of asset.
     * @param array $packageValue The package value of asset.
     */
    public static function formatPackage(PackageInterface $package, string $packageName, array $packageValue): array
    {
        $packageValue['name'] = $packageName;

        if (!isset($packageValue['version'])) {
            $extra = $package->getExtra();
            $version = $package->getPrettyVersion();

            if (str_starts_with($version, 'dev-') && isset($extra['branch-alias'][$version])) {
                $version = $extra['branch-alias'][$version];
            }

            $packageValue['version'] = self::formatVersion(\str_replace('-dev', '', (string) $version));
        }

        return $packageValue;
    }

    /**
     * Format the version for the asset package.
     *
     * @param string $version The branch alias version.
     */
    private static function formatVersion(string $version): string
    {
        $version = \str_replace(['x', 'X', '*'], '0', $version);
        $exp = \explode('.', $version);

        if (($size = \count($exp)) < 3) {
            for ($i = $size; $i < 3; ++$i) {
                $exp[] = '0';
            }
        }

        return $exp[0] . '.' . $exp[1] . '.' . $exp[2];
    }

    /**
     * Get the activation of the package defined in the project config.
     *
     * @param PackageInterface $package The package instance.
     * @param array $configPackages The packages defined in config.
     *
     * @return bool|null returns NULL, if the package isn't defined in the project config
     */
    private static function getProjectActivation(PackageInterface $package, array $configPackages): bool|null
    {
        $name = $package->getName();
        $value = null;

        /**
         * @var array<int|string, bool|string> $configPackages
         */
        foreach ($configPackages as $pattern => $activation) {
            if (\is_int($pattern) && \is_string($activation)) {
                $pattern = $activation;
                $activation = true;
            }

            if (
                \is_string($pattern) &&
                ((str_starts_with($pattern, '/') && \preg_match($pattern, $name)) || \fnmatch($pattern, $name))
            ) {
                $value = $activation;

                break;
            }
        }

        return is_bool($value) ? $value : null;
    }
}
