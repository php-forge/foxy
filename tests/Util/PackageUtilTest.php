<?php

declare(strict_types=1);

namespace Foxy\Tests\Util;

use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Foxy\Util\PackageUtil;
use PHPUnit\Framework\TestCase;

final class PackageUtilTest extends TestCase
{
    public function testConvertLockAlias(): void
    {
        $lockData = [
            'content-hash' => 'HASH_VALUE',
            'aliases' => [
                [
                    'alias' => '1.0.0',
                    'alias_normalized'
                    => '1.0.0.0',
                    'version' => 'dev-feature/1.0-test',
                    'package' => 'foo/bar',
                ],
                [
                    'alias' => '2.2.0',
                    'alias_normalized' => '2.2.0.0',
                    'version' => 'dev-feature/2.2-test',
                    'package' => 'foo/baz',
                ],
            ],
        ];

        $expectedAliases = [
            'foo/bar' => [
                'dev-feature/1.0-test' => [
                    'alias' => '1.0.0',
                    'alias_normalized' => '1.0.0.0',
                ],
            ],
            'foo/baz' => [
                'dev-feature/2.2-test' => [
                    'alias' => '2.2.0',
                    'alias_normalized' => '2.2.0.0',
                ],
            ],
        ];

        $convertedAliases = PackageUtil::convertLockAlias($lockData);

        self::assertArrayHasKey(
            'aliases',
            $convertedAliases,
            'The converted aliases array does not contain the expected key.',
        );
        self::assertSame(
            'HASH_VALUE',
            $convertedAliases['content-hash'],
            'The content hash does not match the expected value.',
        );
        self::assertEquals(
            $expectedAliases,
            $convertedAliases['aliases'],
            'The converted aliases do not match the expected ones.',
        );
    }

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

    public function testLoadLockPackagesCanPreserveRawAliases(): void
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

        $lockDataLoaded = PackageUtil::loadLockPackages($lockData, false);

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

    public function testLoadLockPackagesConvertsAliasesByDefault(): void
    {
        $lockData = [
            'packages' => [
                ['name' => 'foo/bar', 'version' => 'dev-feature'],
            ],
            'aliases' => [
                [
                    'package' => 'foo/bar',
                    'version' => 'dev-feature',
                    'alias' => '1.0.x-dev',
                    'alias_normalized' => '1.0.9999999.9999999-dev',
                ],
            ],
        ];

        $loaded = PackageUtil::loadLockPackages($lockData);

        self::assertSame(
            [
                'dev-feature' => [
                    'alias' => '1.0.x-dev',
                    'alias_normalized' => '1.0.9999999.9999999-dev',
                ],
            ],
            $loaded['aliases']['foo/bar'],
            'The loaded aliases for the package "foo/bar" do not match the expected ones.',
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
}
