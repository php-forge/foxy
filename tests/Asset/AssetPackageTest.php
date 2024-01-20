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

namespace Foxy\Tests\Asset;

use Composer\Json\JsonFile;
use Composer\Package\RootPackageInterface;
use Foxy\Asset\AssetPackage;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Asset package tests.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class AssetPackageTest extends \PHPUnit\Framework\TestCase
{
    protected string|null $cwd = '';
    protected Filesystem|null $sfs = null;
    protected MockObject|RootPackageInterface|null $rootPackage = null;
    protected JsonFile|MockObject|null $jsonFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cwd = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . uniqid('foxy_asset_package_test_', true);
        $this->sfs = new Filesystem();
        $this->rootPackage = $this->createMock(RootPackageInterface::class);
        $this->jsonFile = $this->getMockBuilder(JsonFile::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['exists', 'getPath', 'read', 'write'])
            ->getMock()
        ;

        $this->rootPackage->expects($this->any())->method('getLicense')->willReturn([]);

        $this->sfs->mkdir($this->cwd);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->sfs->remove($this->cwd);
        $this->jsonFile = null;
        $this->rootPackage = null;
        $this->sfs = null;
        $this->cwd = null;
    }

    public function testGetPackageWithExistingFile(): void
    {
        $package = ['name' => '@foo/bar'];
        $contentString = json_encode($package);
        $this->addPackageFile($package, $contentString);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);

        $this->assertSame($package, $assetPackage->getPackage());
    }

    public function testWrite(): void
    {
        $package = ['name' => '@foo/bar'];

        $this->jsonFile->expects($this->once())->method('exists')->willReturn(false);
        $this->jsonFile->expects($this->once())->method('write')->with($package);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);

        $assetPackage->setPackage($package);
        $assetPackage->write();
    }

    public static function getDataRequiredKeys(): array
    {
        return [
            [
                [
                    'name' => '@foo/bar',
                    'license' => 'MIT',
                ],
                [
                    'name' => '@foo/bar',
                    'license' => 'MIT',
                ],
                'proprietary',
            ],
            [
                [
                    'name' => '@foo/bar',
                    'license' => 'MIT',
                ],
                [
                    'name' => '@foo/bar',
                ],
                'MIT',
            ],
            [
                [
                    'name' => '@foo/bar',
                    'private' => true,
                ],
                [
                    'name' => '@foo/bar',
                ],
                'proprietary',
            ],
        ];
    }

    /**
     * @dataProvider getDataRequiredKeys
     */
    public function testInjectionOfRequiredKeys(array $expected, array $package, string $license): void
    {
        $this->addPackageFile($package);

        $this->rootPackage = $this->createMock(RootPackageInterface::class);

        $this->rootPackage->expects($this->any())->method('getLicense')->willReturn([$license]);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);

        $this->assertEquals($expected, $assetPackage->getPackage());
    }

    public function testGetInstalledDependencies(): void
    {
        $expected = [
            '@composer-asset/foo--bar' => 'file:./path/foo/bar',
            '@composer-asset/baz--bar' => 'file:./path/baz/bar',
        ];
        $package = [
            'dependencies' => [
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@bar/foo' => '^1.0.0',
                '@composer-asset/baz--bar' => 'file:./path/baz/bar',
            ],
        ];

        $this->addPackageFile($package);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);

        $this->assertEquals($expected, $assetPackage->getInstalledDependencies());
    }

    public function testAddNewDependencies(): void
    {
        $expected = [
            'dependencies' => [
                '@bar/foo' => '^1.0.0',
                '@composer-asset/baz--bar' => 'file:./path/baz/bar',
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@composer-asset/new--dependency' => 'file:./path/new/dependency',
            ],
        ];
        $expectedExisting = ['@composer-asset/foo--bar', '@composer-asset/baz--bar'];

        $package = [
            'dependencies' => [
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@bar/foo' => '^1.0.0',
                '@composer-asset/baz--bar' => 'file:./path/baz/bar',
            ],
        ];
        $dependencies = [
            '@composer-asset/foo--bar' => 'path/foo/bar/package.json',
            '@composer-asset/baz--bar' => 'path/baz/bar/package.json',
            '@composer-asset/new--dependency' => 'path/new/dependency/package.json',
        ];

        $this->addPackageFile($package);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);
        $existing = $assetPackage->addNewDependencies($dependencies);

        $this->assertSame($expected, $assetPackage->getPackage());
        $this->assertSame($expectedExisting, $existing);
    }

    public function testRemoveUnusedDependencies(): void
    {
        $expected = [
            'dependencies' => [
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@bar/foo' => '^1.0.0',
            ],
        ];

        $package = [
            'dependencies' => [
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@bar/foo' => '^1.0.0',
                '@composer-asset/baz--bar' => 'file:./path/baz/bar',
            ],
        ];
        $dependencies = ['@composer-asset/foo--bar' => 'file:./path/foo/bar'];

        $this->addPackageFile($package);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);
        $assetPackage->removeUnusedDependencies($dependencies);

        $this->assertEquals($expected, $assetPackage->getPackage());
    }

    /**
     * Add the package in file.
     *
     * @param array $package The package.
     * @param null|string $contentString The string content of package.
     */
    protected function addPackageFile(array $package, $contentString = null): void
    {
        $filename = $this->cwd . '/package.json';
        $contentString ??= json_encode($package);

        $this->jsonFile->expects($this->any())->method('exists')->willReturn(true);
        $this->jsonFile->expects($this->any())->method('getPath')->willReturn($filename);
        $this->jsonFile->expects($this->any())->method('read')->willReturn($package);

        \file_put_contents($filename, $contentString);
    }
}
