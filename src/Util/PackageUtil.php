<?php

declare(strict_types=1);

namespace Foxy\Util;

use Composer\Package\AliasPackage;
use Composer\Package\Loader\ArrayLoader;

final class PackageUtil
{
    /**
     * Load the packages in the packages section of the locker load data.
     *
     * @param ArrayLoader $loader The package loader of composer.
     * @param array $lockData The lock data of locker.
     * @param bool $dev Check if the dev packages must be loaded.
     *
     * @return array The lock data
     */
    public static function loadLockPackage(ArrayLoader $loader, array $lockData, bool $dev = false): array
    {
        $key = $dev ? 'packages-dev' : 'packages';

        /** @var array[] $loadDataWithKeys */
        $loadDataWithKeys = $lockData[$key] ?? [];

        if ($loadDataWithKeys === []) {
            return $lockData;
        }

        foreach ($loadDataWithKeys as $index => $package) {
            $package = $loader->load($package);
            $package = $package instanceof AliasPackage ? $package->getAliasOf() : $package;
            $loadDataWithKeys[$index] = $package;
        }

        $lockData[$key] = $loadDataWithKeys;

        return $lockData;
    }

    /**
     * Loads the `packages` and `packages-dev` entries of the lock data, leaving every other key unchanged.
     *
     * @param array $lockData The raw lock data, as read from the lock file.
     *
     * @return array The lock data with package entries replaced by package instances.
     */
    public static function loadLockPackages(array $lockData): array
    {
        $loader = new ArrayLoader();

        $lockData = self::loadLockPackage($loader, $lockData);

        return self::loadLockPackage($loader, $lockData, true);
    }
}
