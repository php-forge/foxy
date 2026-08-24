<?php

declare(strict_types=1);

namespace Foxy\Util;

use Composer\Installer\InstallationManager;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Foxy\Asset\AssetManagerInterface;
use Foxy\Asset\AssetPackage;
use Foxy\Exception\RuntimeException;
use JsonException;

use function array_flip;
use function array_intersect_key;
use function count;
use function explode;
use function fnmatch;
use function is_bool;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function preg_match;
use function realpath;
use function sprintf;
use function str_replace;
use function str_starts_with;

final class AssetUtil
{
    /**
     * Package metadata required to resolve transitive asset dependencies safely.
     */
    private const array SAFE_PACKAGE_KEYS = [
        'bundleDependencies',
        'bundledDependencies',
        'cpu',
        'dependencies',
        'engines',
        'name',
        'optionalDependencies',
        'os',
        'overrides',
        'peerDependencies',
        'peerDependenciesMeta',
        'resolutions',
        'version',
    ];

    /**
     * Format the asset package.
     *
     * @param PackageInterface $package The composer package instance.
     * @param string $packageName  The package name of asset.
     * @param array $packageValue The package value of asset.
     */
    public static function formatPackage(PackageInterface $package, string $packageName, array $packageValue): array
    {
        $packageValue = array_intersect_key($packageValue, array_flip(self::SAFE_PACKAGE_KEYS));

        $packageValue['name'] = $packageName;

        if (!isset($packageValue['version'])) {
            $extra = $package->getExtra();
            $version = $package->getPrettyVersion();

            if (str_starts_with($version, 'dev-') && isset($extra['branch-alias'][$version])) {
                $version = $extra['branch-alias'][$version];
            }

            $packageValue['version'] = self::formatVersion(str_replace('-dev', '', (string) $version));
        }

        return $packageValue;
    }

    /**
     * Get the name for the asset dependency.
     *
     * @param PackageInterface $package The package instance.
     */
    public static function getName(PackageInterface $package): string
    {
        return AssetPackage::COMPOSER_PREFIX . str_replace(['/'], '--', $package->getName());
    }

    /**
     * Get the path of asset file.
     *
     * @param InstallationManager $installationManager The installation manager.
     * @param AssetManagerInterface $assetManager The asset manager.
     * @param PackageInterface $package The package instance.
     * @param array $configPackages The packages defined in config.
     *
     * @throws JsonException
     */
    public static function getPath(
        InstallationManager $installationManager,
        AssetManagerInterface $assetManager,
        PackageInterface $package,
        array $configPackages = [],
    ): string|null {
        if (!self::isAsset($package, $configPackages)) {
            return null;
        }

        $installPath = $installationManager->getInstallPath($package);

        if (null === $installPath) {
            return null;
        }

        $installRoot = realpath($installPath);

        if (false === $installRoot || !is_dir($installRoot)) {
            return null;
        }

        $packageName = $assetManager->getPackageName();

        $composerJsonPath = "{$installRoot}/composer.json";

        if (is_file($composerJsonPath)) {
            $content = file_get_contents($composerJsonPath);

            if (false === $content) {
                throw new RuntimeException(
                    sprintf('Unable to read Composer package file "%s".', $composerJsonPath),
                );
            }

            /** @var array[] $composerJson */
            $composerJson = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

            $rootPackageDir = $composerJson['config']['foxy']['root-package-json-dir'] ?? null;

            if (is_string($rootPackageDir) && '' !== $rootPackageDir) {
                return self::resolvePackagePath(
                    $installRoot . '/' . $rootPackageDir . '/' . $packageName,
                    $installRoot,
                );
            }
        }

        return self::resolvePackagePath("{$installRoot}/{$packageName}", $installRoot);
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
     * @param Link[] $requires The requirement links.
     *
     * @psalm-param Link[] $requires The requirement links.
     */
    public static function hasPluginDependency(array $requires): bool
    {
        foreach ($requires as $require) {
            if ('php-forge/foxy' === $require->getTarget()) {
                return true;
            }
        }

        return false;
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
     * Format the version for the asset package.
     *
     * @param string $version The branch alias version.
     */
    private static function formatVersion(string $version): string
    {
        $version = str_replace(['x', 'X', '*'], '0', $version);
        $exp = explode('.', $version);

        if (($size = count($exp)) < 3) {
            for ($i = $size; $i < 3; ++$i) {
                $exp[] = '0';
            }
        }

        return "{$exp[0]}.{$exp[1]}.{$exp[2]}";
    }

    /**
     * Get the activation of the package defined in the project config.
     *
     * @param PackageInterface $package The package instance.
     * @param array<int|string, bool|string> $configPackages The packages defined in config.
     *
     * @return bool|null returns NULL, if the package isn't defined in the project config
     */
    private static function getProjectActivation(PackageInterface $package, array $configPackages): bool|null
    {
        $name = $package->getName();
        $value = null;

        foreach ($configPackages as $pattern => $activation) {
            if (is_int($pattern) && is_string($activation)) {
                $pattern = $activation;
                $activation = true;
            }

            if (
                is_string($pattern)
                && (str_starts_with($pattern, '/') && preg_match($pattern, $name) || fnmatch($pattern, $name))
            ) {
                $value = $activation;

                break;
            }
        }

        return is_bool($value) ? $value : null;
    }

    /**
     * Resolve a package manifest and ensure it stays inside the Composer install path.
     */
    private static function resolvePackagePath(string $filename, string $installRoot): string|null
    {
        $resolvedPath = realpath($filename);

        if (false === $resolvedPath || !is_file($resolvedPath)) {
            return null;
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $installRoot), '/');
        $normalizedPath = str_replace('\\', '/', $resolvedPath);

        if ($normalizedPath !== $normalizedRoot && !str_starts_with($normalizedPath, "{$normalizedRoot}/")) {
            throw new RuntimeException(
                sprintf('The asset package path "%s" escapes its Composer install directory.', $normalizedPath),
            );
        }

        return $normalizedPath;
    }
}
