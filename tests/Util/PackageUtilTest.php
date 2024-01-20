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

namespace Foxy\Tests\Util;

use Composer\Package\CompletePackage;
use Foxy\Util\PackageUtil;

/**
 * Tests for package util.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class PackageUtilTest extends \PHPUnit\Framework\TestCase
{
    public function testLoadLockPackages(): void
    {
        $lockData = [
            'packages' => [
                ['name' => 'foo/bar', 'version' => '1.0.0.0']
            ],
            'packages-dev' => [
                ['name' => 'bar/foo', 'version' => '1.0.0.0']
            ],
        ];

        $package = new CompletePackage('foo/bar', '1.0.0.0', '1.0.0.0');
        $package->setType('library');

        $packageDev = new CompletePackage('bar/foo', '1.0.0.0', '1.0.0.0');
        $packageDev->setType('library');

        $expectedPackages = [$package];
        $expectedDevPackages = [$packageDev];

        $lockDataLoaded = PackageUtil::loadLockPackages($lockData);

        $this->assertArrayHasKey('packages', $lockDataLoaded);
        $this->assertArrayHasKey('packages-dev', $lockDataLoaded);
        $this->assertEquals($lockDataLoaded['packages'], $expectedPackages);
        $this->assertEquals($lockDataLoaded['packages-dev'], $expectedDevPackages);
    }

    public function testLoadLockPackagesWithoutPackages(): void
    {
        $this->assertSame([], PackageUtil::loadLockPackages([]));
    }

    public function testConvertLockAlias(): void
    {
        $lockData = [
            'aliases' => [
                [
                    'alias' => '1.0.0',
                    'alias_normalized' =>
                    '1.0.0.0',
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
                    'alias_normalized' => '1.0.0.0'
                ]
            ],
            'foo/baz' => [
                'dev-feature/2.2-test' => [
                    'alias' => '2.2.0',
                    'alias_normalized' => '2.2.0.0',
                ],
            ],
        ];

        $convertedAliases = PackageUtil::convertLockAlias($lockData);

        $this->assertArrayHasKey('aliases', $convertedAliases);
        $this->assertEquals($convertedAliases['aliases'], $expectedAliases);
    }
}
