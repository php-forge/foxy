<?php

declare(strict_types=1);

namespace Foxy\Tests\Util;

use Composer\Package\{AliasPackage, CompletePackage};
use Composer\Package\Loader\ArrayLoader;
use Foxy\Util\PackageUtil;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PackageUtil} lock package hydration.
 */
final class PackageUtilTest extends TestCase
{
    public function testLoadLockPackageLoadsOnlyRequestedSection(): void
    {
        $lockData = [
            'packages' => [
                ['name' => 'foo/bar', 'version' => '1.0.0.0'],
            ],
            'packages-dev' => [
                ['name' => 'bar/foo', 'version' => '2.0.0.0'],
            ],
        ];

        $loaded = PackageUtil::loadLockPackage(new ArrayLoader(), $lockData, true);

        self::assertIsArray(
            $loaded['packages'][0],
            'The loaded package is not an array as expected.',
        );
        self::assertInstanceOf(
            CompletePackage::class,
            $loaded['packages-dev'][0],
            'The loaded dev package is not an instance of CompletePackage as expected.',
        );
    }

    public function testLoadLockPackages(): void
    {
        $lockData = [
            'packages' => [
                ['name' => 'foo/bar', 'version' => '1.0.0.0'],
            ],
            'packages-dev' => [
                ['name' => 'bar/foo', 'version' => '1.0.0.0'],
            ],
        ];

        $package = new CompletePackage('foo/bar', '1.0.0.0', '1.0.0.0');

        $package->setType('library');

        $packageDev = new CompletePackage('bar/foo', '1.0.0.0', '1.0.0.0');

        $packageDev->setType('library');

        $expectedPackages = [$package];
        $expectedDevPackages = [$packageDev];

        $lockDataLoaded = PackageUtil::loadLockPackages($lockData);

        self::assertArrayHasKey(
            'packages',
            $lockDataLoaded,
            'The loaded lock data does not contain the expected "packages" key.',
        );
        self::assertArrayHasKey(
            'packages-dev',
            $lockDataLoaded,
            'The loaded lock data does not contain the expected "packages-dev" key.',
        );
        self::assertEquals(
            $expectedPackages,
            $lockDataLoaded['packages'],
            'The loaded packages do not match the expected ones.',
        );
        self::assertEquals(
            $expectedDevPackages,
            $lockDataLoaded['packages-dev'],
            'The loaded dev packages do not match the expected ones.',
        );
    }

    public function testLoadLockPackagesPreservesRawAliases(): void
    {
        $aliases = [
            [
                'package' => 'foo/bar',
                'version' => 'dev-feature',
                'alias' => '1.0.x-dev',
                'alias_normalized' => '1.0.9999999.9999999-dev',
            ],
        ];
        $lockData = [
            'packages' => [
                ['name' => 'foo/bar', 'version' => 'dev-feature'],
            ],
            'aliases' => $aliases,
        ];

        $lockDataLoaded = PackageUtil::loadLockPackages($lockData);

        self::assertInstanceOf(
            CompletePackage::class,
            $lockDataLoaded['packages'][0],
            'The loaded package is not an instance of CompletePackage as expected.',
        );
        self::assertSame(
            $aliases,
            $lockDataLoaded['aliases'],
            'The loaded aliases do not match the expected ones.',
        );
    }

    public function testLoadLockPackagesWithoutPackages(): void
    {
        self::assertSame(
            [],
            PackageUtil::loadLockPackages([]),
            'The loaded lock data for an empty input does not match the expected empty array.',
        );
    }

    public function testLoadLockPackageUnwrapsBranchAliasPackage(): void
    {
        $lockData = [
            'packages' => [
                [
                    'name' => 'foo/bar',
                    'version' => 'dev-main',
                    'extra' => ['branch-alias' => ['dev-main' => '1.0.x-dev']],
                ],
            ],
        ];

        $loaded = PackageUtil::loadLockPackage(new ArrayLoader(), $lockData);

        self::assertInstanceOf(
            CompletePackage::class,
            $loaded['packages'][0],
            'The loaded package should be the aliased package.',
        );
        self::assertNotInstanceOf(
            AliasPackage::class,
            $loaded['packages'][0],
            'The branch alias wrapper should be removed.',
        );
        self::assertSame(
            'dev-main',
            $loaded['packages'][0]->getPrettyVersion(),
            'The loaded package should keep the locked version.',
        );
    }
}
