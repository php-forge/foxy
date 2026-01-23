<?php

declare(strict_types=1);

namespace Foxy\Tests\Util;

use Composer\Package\CompletePackage;
use Foxy\Util\PackageUtil;
use PHPUnit\Framework\TestCase;

final class PackageUtilTest extends TestCase
{
    public function testConvertLockAlias(): void
    {
        $lockData = [
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

        self::assertArrayHasKey('aliases', $convertedAliases);
        self::assertEquals($expectedAliases, $convertedAliases['aliases']);
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

        self::assertArrayHasKey('packages', $lockDataLoaded);
        self::assertArrayHasKey('packages-dev', $lockDataLoaded);
        self::assertEquals($expectedPackages, $lockDataLoaded['packages']);
        self::assertEquals($expectedDevPackages, $lockDataLoaded['packages-dev']);
    }

    public function testLoadLockPackagesWithoutPackages(): void
    {
        self::assertSame([], PackageUtil::loadLockPackages([]));
    }
}
