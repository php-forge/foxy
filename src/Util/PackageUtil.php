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

use Composer\Package\AliasPackage;
use Composer\Package\Loader\ArrayLoader;

/**
 * Helper for package.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class PackageUtil
{
    /**
     * Load all packages in the lock data of locker.
     *
     * @param array $lockData The lock data of locker.
     *
     * @return array The lock data.
     */
    public static function loadLockPackages(array $lockData): array
    {
        $loader = new ArrayLoader();
        $lockData = self::loadLockPackage($loader, $lockData);
        $lockData = self::loadLockPackage($loader, $lockData, true);
        return self::convertLockAlias($lockData);
    }

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

        $loadDataWithKeys = $lockData[$key] ?? [];

        if ($loadDataWithKeys === []) {
            return $lockData;
        }

        /**
         * @psalm-var array[] $loadDataWithKeys
         */
        foreach ($loadDataWithKeys as $index => $package) {
            $package = $loader->load($package);
            $package = $package instanceof AliasPackage ? $package->getAliasOf() : $package;
            $loadDataWithKeys[$index] = $package;
        }

        $lockData[$key] = $loadDataWithKeys;

        return $lockData;
    }

    /**
     * Convert the package aliases of the locker load data.
     *
     * @param array $lockData The lock data of locker.
     *
     * @return array The lock data.
     */
    public static function convertLockAlias(array $lockData): array
    {
        $loadDatawithaliases = $lockData['aliases'] ?? [];

        if ($loadDatawithaliases === []) {
            return $lockData;
        }

        $alias = [];

        /**
         * @psalm-var array{
         *   array{alias: string, alias_normalized: string, version: string, package: string}
         * } $loadDatawithaliases
         */
        foreach ($loadDatawithaliases as $config) {
            $alias[$config['package']][$config['version']] = [
                'alias' => $config['alias'],
                'alias_normalized' => $config['alias_normalized'],
            ];
        }

        $lockData['aliases'] = $alias;

        return $lockData;
    }
}
