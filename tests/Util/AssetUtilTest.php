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

use Composer\Installer\InstallationManager;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;
use Foxy\Asset\AbstractAssetManager;
use Foxy\Asset\AssetManagerInterface;
use Foxy\Util\AssetUtil;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests for asset util.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class AssetUtilTest extends \PHPUnit\Framework\TestCase
{
    private Filesystem|null $sfs;
    private string|null $cwd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cwd = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . uniqid('foxy_asset_util_test_', true);
        $this->sfs = new Filesystem();
        $this->sfs->mkdir($this->cwd);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->sfs->remove($this->cwd);
        $this->sfs = null;
        $this->cwd = null;
    }

    public function testGetName(): void
    {
        $package = $this->createMock(PackageInterface::class);
        $package->expects($this->once())->method('getName')->willReturn('foo/bar');

        $this->assertSame('@composer-asset/foo--bar', AssetUtil::getName($package));
    }

    public function testGetPathWithoutRequiredFoxy(): void
    {
        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager->expects($this->never())->method('getInstallPath');

        $assetManager = $this->createMock(AbstractAssetManager::class);

        /** @var MockObject|PackageInterface $package */
        $package = $this->createMock(PackageInterface::class);

        $package->expects($this->once())->method('getRequires')->willReturn([]);
        $package->expects($this->once())->method('getDevRequires')->willReturn([]);

        $res = AssetUtil::getPath($installationManager, $assetManager, $package);

        $this->assertNull($res);
    }

    public static function getRequiresData(): array
    {
        return [
            [
                [new Link('root/package', 'php-forge/foxy', new Constraint('=', '1.0.0'))],
                [],
                false,
            ],
            [
                [],
                [new Link('root/package', 'php-forge/foxy', new Constraint('=', '1.0.0'))],
                false,
            ],
            [
                [new Link('root/package', 'php-forge/foxy', new Constraint('=', '1.0.0'))],
                [],
                true,
            ],
            [
                [],
                [new Link('root/package', 'php-forge/foxy', new Constraint('=', '1.0.0'))],
                true,
            ],
        ];
    }

    /**
     * @dataProvider getRequiresData
     *
     * @param Link[] $requires
     * @param Link[] $devRequires
     */
    public function testGetPathWithRequiredFoxy(array $requires, array $devRequires, bool $fileExists = false): void
    {
        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager->expects($this->once())->method('getInstallPath')->willReturn($this->cwd);

        /** @var AbstractAssetManager|MockObject $assetManager */
        $assetManager = $this
            ->getMockBuilder(AbstractAssetManager::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $package = $this->createMock(PackageInterface::class);

        $package->expects($this->once())->method('getRequires')->willReturn($requires);

        if (0 === \count($devRequires)) {
            $package->expects($this->never())->method('getDevRequires');
        } else {
            $package->expects($this->once())->method('getDevRequires')->willReturn($devRequires);
        }

        if ($fileExists) {
            $expectedFilename = $this->cwd . \DIRECTORY_SEPARATOR . $assetManager->getPackageName();

            \file_put_contents($expectedFilename, '{}');

            $expectedFilename = \str_replace('\\', '/', \realpath($expectedFilename));
        } else {
            $expectedFilename = null;
        }

        $res = AssetUtil::getPath($installationManager, $assetManager, $package);

        $this->assertSame($expectedFilename, $res);
    }

    public static function getExtraData(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    /**
     * @dataProvider getExtraData
     */
    public function testGetPathWithExtraActivation(bool $withExtra, bool $fileExists = false): void
    {
        $installationManager = $this->createMock(InstallationManager::class);

        if ($withExtra && $fileExists) {
            $installationManager->expects($this->once())->method('getInstallPath')->willReturn($this->cwd);
        }

        /** @var AbstractAssetManager|MockObject $assetManager */
        $assetManager = $this
            ->getMockBuilder(AbstractAssetManager::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $package = $this->createMock(PackageInterface::class);

        $package->expects($this->any())->method('getRequires')->willReturn([]);
        $package->expects($this->any())->method('getDevRequires')->willReturn([]);
        $package->expects($this->atLeastOnce())->method('getExtra')->willReturn(['foxy' => $withExtra]);

        if ($fileExists) {
            $expectedFilename = $this->cwd . \DIRECTORY_SEPARATOR . $assetManager->getPackageName();

            \file_put_contents($expectedFilename, '{}');

            $expectedFilename = $withExtra ? \str_replace('\\', '/', \realpath($expectedFilename)) : null;
        } else {
            $expectedFilename = null;
        }

        $res = AssetUtil::getPath($installationManager, $assetManager, $package);

        $this->assertSame($expectedFilename, $res);
    }

    public function testHasNoPluginDependency(): void
    {
        $this->assertFalse(
            AssetUtil::hasPluginDependency([new Link('root/package', 'foo/bar', new Constraint('=', '1.0.0'))])
        );
    }

    public function testHasPluginDependency(): void
    {
        $this->assertTrue(
            AssetUtil::hasPluginDependency(
                [
                    new Link('root/package', 'foo/bar', new Constraint('=', '1.0.0')),
                    new Link('root/package', 'php-forge/foxy', new Constraint('=', '1.0.0')),
                    new Link('root/package', 'bar/foo', new Constraint('=', '1.0.0')),
                ],
            )
        );
    }

    public static function getIsProjectActivationData(): array
    {
        return [
            ['full/qualified', true],
            ['full-disable/qualified', false],
            ['foo/bar', true],
            ['baz/foo', false],
            ['baz/foo-test', false],
            ['bar/test', true],
            ['other/package', false],
            ['test-string/package', true],
        ];
    }

    /**
     * @dataProvider getIsProjectActivationData
     */
    public function testIsProjectActivation(string $packageName, bool $expected): void
    {
        $enablePackages = [
            0 => 'test-string/*',
            'foo/*' => true,
            'baz/foo' => false,
            '/^bar\/*/' => true,
            'full/qualified' => true,
            'full-disable/qualified' => false,
        ];

        /** @var MockObject|PackageInterface $package */
        $package = $this->createMock(PackageInterface::class);

        $package->expects($this->once())->method('getName')->willReturn($packageName);

        $res = AssetUtil::isProjectActivation($package, $enablePackages);

        $this->assertSame($expected, $res);
    }

    public static function getIsProjectActivationWithWildcardData(): array
    {
        return [
            ['full/qualified', true],
            ['full-disable/qualified', false],
            ['foo/bar', true],
            ['baz/foo', false],
            ['baz/foo-test', false],
            ['bar/test', true],
            ['other/package', true],
            ['test-string/package', true],
        ];
    }

    /**
     * @dataProvider getIsProjectActivationWithWildcardData
     */
    public function testIsProjectActivationWithWildcardPattern(string $packageName, bool $expected): void
    {
        $enablePackages = [
            'baz/foo*' => false,
            'full-disable/qualified' => false,
            '*' => true,
        ];

        /** @var MockObject|PackageInterface $package */
        $package = $this->createMock(PackageInterface::class);

        $package->expects($this->once())->method('getName')->willReturn($packageName);

        $res = AssetUtil::isProjectActivation($package, $enablePackages);

        $this->assertSame($expected, $res);
    }

    public static function getFormatPackageData(): array
    {
        return [
            ['1.0.0', null, '1.0.0'],
            ['1.0.1', '1.0.0', '1.0.0'],
            ['1.0.0.x-dev', null, '1.0.0'],
            ['1.0.0.x', null, '1.0.0'],
            ['1.0.0.1', null, '1.0.0'],
            ['dev-master', null, '1.0.0', '1-dev'],
            ['dev-master', null, '1.0.0', '1.0-dev'],
            ['dev-master', null, '1.0.0', '1.0.0-dev'],
            ['dev-master', null, '1.0.0', '1.x-dev'],
            ['dev-master', null, '1.0.0', '1.0.x-dev'],
            ['dev-master', null, '1.0.0', '1.*-dev'],
            ['dev-master', null, '1.0.0', '1.0.*-dev'],
        ];
    }

    /**
     * @dataProvider getFormatPackageData
     */
    public function testFormatPackage(
        string $packageVersion,
        string|null $assetVersion,
        string $expectedAssetVersion,
        string $branchAlias = null
    ): void {
        $packageName = '@composer-asset/foo--bar';

        /** @var MockObject|PackageInterface $package */
        $package = $this->createMock(PackageInterface::class);

        $assetPackage = [];

        if (null !== $assetVersion) {
            $assetPackage['version'] = $assetVersion;

            $package->expects($this->never())->method('getPrettyVersion');
            $package->expects($this->never())->method('getExtra');
        } else {
            $extra = [];

            if (null !== $branchAlias) {
                $extra['branch-alias'][$packageVersion] = $branchAlias;
            }

            $package->expects($this->once())->method('getPrettyVersion')->willReturn($packageVersion);
            $package->expects($this->once())->method('getExtra')->willReturn($extra);
        }

        $expected = ['name' => $packageName, 'version' => $expectedAssetVersion];

        $res = AssetUtil::formatPackage($package, $packageName, $assetPackage);

        $this->assertEquals($expected, $res);
    }

    public function testGetPathWithRootPackageDir(): void
    {
        $installationManager = $this->createMock(InstallationManager::class);
        $installationManager
            ->expects($this->once())
            ->method('getInstallPath')
            ->willReturn('tests/Fixtures/package/global');

        $assetManager = $this->createMock(AssetManagerInterface::class);
        $assetManager->expects($this->once())->method('getPackageName')->willReturn('foo/bar');

        $package = $this->createMock(PackageInterface::class);
        $package->expects($this->once())->method('getName')->willReturn('foo/bar');
        $package->expects($this->once())->method('getRequires')->willReturn([]);
        $package->expects($this->once())->method('getDevRequires')->willReturn([]);

        $configPackages = [
            '/^foo\/bar$/' => true,
        ];

        $expectedPath = 'tests/Fixtures/package/global/theme/foo/bar';

        $res = AssetUtil::getPath($installationManager, $assetManager, $package, $configPackages);

        $this->assertStringContainsString($expectedPath, $res);
    }
}
