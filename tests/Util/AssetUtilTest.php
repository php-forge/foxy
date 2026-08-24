<?php

declare(strict_types=1);

namespace Foxy\Tests\Util;

use Composer\Installer\InstallationManager;
use Composer\Package\{Link, PackageInterface};
use Composer\Semver\Constraint\Constraint;
use Foxy\Asset\{AbstractAssetManager, AssetManagerInterface};
use Foxy\Exception\RuntimeException;
use Foxy\Util\AssetUtil;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Xepozz\InternalMocker\MockerState;

use function count;
use function file_put_contents;
use function ltrim;
use function realpath;
use function str_replace;

use const DIRECTORY_SEPARATOR;

final class AssetUtilTest extends TestCase
{
    private string|null $cwd;
    private Filesystem|null $sfs;

    public static function getExtraData(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
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

    #[DataProvider('getFormatPackageData')]
    public function testFormatPackage(
        string $packageVersion,
        string|null $assetVersion,
        string $expectedAssetVersion,
        string|null $branchAlias = null,
    ): void {
        $packageName = '@composer-asset/foo--bar';

        $package = $this->createMock(PackageInterface::class);

        $assetPackage = [];

        if (null !== $assetVersion) {
            $assetPackage['version'] = $assetVersion;

            $package->expects(self::never())->method('getPrettyVersion');
            $package->expects(self::never())->method('getExtra');
        } else {
            $extra = [];

            if (null !== $branchAlias) {
                $extra['branch-alias'][$packageVersion] = $branchAlias;
            }

            $package->expects(self::once())->method('getPrettyVersion')->willReturn($packageVersion);
            $package->expects(self::once())->method('getExtra')->willReturn($extra);
        }

        $expected = ['name' => $packageName, 'version' => $expectedAssetVersion];

        $res = AssetUtil::formatPackage($package, $packageName, $assetPackage);

        self::assertEquals($expected, $res);
    }

    public function testFormatPackageIgnoresBranchAliasForTaggedVersion(): void
    {
        $package = $this->createMock(PackageInterface::class);
        $package->expects(self::once())->method('getPrettyVersion')->willReturn('1.2.3');
        $package
            ->expects(self::once())
            ->method('getExtra')
            ->willReturn(['branch-alias' => ['1.2.3' => '9.9.x-dev']]);

        self::assertSame(
            ['name' => '@composer-asset/foo--bar', 'version' => '1.2.3'],
            AssetUtil::formatPackage($package, '@composer-asset/foo--bar', []),
        );
    }

    public function testFormatPackageStripsExecutableAndUnneededMetadata(): void
    {
        $package = $this->createMock(PackageInterface::class);
        $package->method('getPrettyVersion')->willReturn('1.2.3');
        $package->method('getExtra')->willReturn([]);

        $formatted = AssetUtil::formatPackage(
            $package,
            '@composer-asset/foo--bar',
            [
                'scripts' => ['install' => 'touch compromised'],
                'bin' => ['tool' => 'bin/tool'],
                'main' => 'index.js',
                'dependencies' => ['safe-package' => '^1.0'],
            ],
        );

        self::assertSame(
            [
                'dependencies' => ['safe-package' => '^1.0'],
                'name' => '@composer-asset/foo--bar',
                'version' => '1.2.3',
            ],
            $formatted,
        );
    }

    public function testGetName(): void
    {
        $package = $this->createMock(PackageInterface::class);
        $package->expects(self::once())->method('getName')->willReturn('foo/bar');

        self::assertSame('@composer-asset/foo--bar', AssetUtil::getName($package));
    }

    /**
     * @throws JsonException
     */
    public function testGetPathAcceptsInstallPathWithTrailingSeparator(): void
    {
        $installPath = $this->cwd . '/trailing-separator-package';
        $this->sfs->mkdir($installPath);
        file_put_contents($installPath . '/package.json', '{}');

        $installationManager = $this->createMock(InstallationManager::class);
        $installationManager
            ->expects(self::once())
            ->method('getInstallPath')
            ->willReturn($installPath . DIRECTORY_SEPARATOR);

        $assetManager = $this->createMock(AssetManagerInterface::class);
        $assetManager->expects(self::once())->method('getPackageName')->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);
        $package->method('getExtra')->willReturn(['foxy' => true]);
        $package->method('getRequires')->willReturn([]);
        $package->method('getDevRequires')->willReturn([]);

        self::assertSame(
            str_replace('\\', '/', (string) realpath($installPath . '/package.json')),
            AssetUtil::getPath($installationManager, $assetManager, $package),
        );
    }

    /**
     * @throws JsonException
     */
    public function testGetPathAcceptsManifestWithinFilesystemRoot(): void
    {
        if ('/' !== DIRECTORY_SEPARATOR) {
            self::markTestSkipped('This filesystem-root regression is specific to Unix paths.');
        }

        $manifest = $this->cwd . '/root-install-package.json';
        file_put_contents($manifest, '{}');

        $installationManager = $this->createMock(InstallationManager::class);
        $installationManager->expects(self::once())->method('getInstallPath')->willReturn(DIRECTORY_SEPARATOR);

        $assetManager = $this->createMock(AssetManagerInterface::class);
        $assetManager->expects(self::once())->method('getPackageName')->willReturn(ltrim($manifest, '/'));

        $package = $this->createMock(PackageInterface::class);
        $package->method('getName')->willReturn('root/package');
        $package->method('getExtra')->willReturn(['foxy' => true]);
        $package->method('getRequires')->willReturn([]);
        $package->method('getDevRequires')->willReturn([]);

        self::assertSame(
            str_replace('\\', '/', (string) realpath($manifest)),
            AssetUtil::getPath($installationManager, $assetManager, $package),
        );
    }

    /**
     * @throws JsonException
     */
    public function testGetPathPrefersConfiguredManifestOverRootManifest(): void
    {
        $installPath = $this->cwd . '/configured-package';
        $configuredDirectory = $installPath . '/resources';

        $this->sfs->mkdir($configuredDirectory);
        file_put_contents(
            $installPath . '/composer.json',
            '{"config":{"foxy":{"root-package-json-dir":"resources"}}}',
        );
        file_put_contents($installPath . '/package.json', '{"source":"root"}');
        file_put_contents($configuredDirectory . '/package.json', '{"source":"configured"}');

        $installationManager = $this->createMock(InstallationManager::class);
        $installationManager->expects(self::once())->method('getInstallPath')->willReturn($installPath);

        $assetManager = $this->createMock(AssetManagerInterface::class);
        $assetManager->expects(self::once())->method('getPackageName')->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);
        $package->method('getExtra')->willReturn(['foxy' => true]);
        $package->method('getRequires')->willReturn([]);
        $package->method('getDevRequires')->willReturn([]);

        self::assertSame(
            str_replace('\\', '/', (string) realpath($configuredDirectory . '/package.json')),
            AssetUtil::getPath($installationManager, $assetManager, $package),
        );
    }

    /**
     * @throws JsonException
     */
    public function testGetPathRejectsInvalidComposerMetadataEvenWhenRootManifestExists(): void
    {
        $installPath = $this->cwd . '/direct-package';
        $this->sfs->mkdir($installPath);
        file_put_contents($installPath . '/composer.json', 'invalid json');
        file_put_contents($installPath . '/package.json', '{}');

        $installationManager = $this->createMock(InstallationManager::class);
        $installationManager->expects(self::once())->method('getInstallPath')->willReturn($installPath);

        $assetManager = $this->createMock(AssetManagerInterface::class);
        $assetManager->expects(self::once())->method('getPackageName')->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);
        $package->method('getExtra')->willReturn(['foxy' => true]);
        $package->method('getRequires')->willReturn([]);
        $package->method('getDevRequires')->willReturn([]);

        $this->expectException(JsonException::class);

        AssetUtil::getPath($installationManager, $assetManager, $package);
    }

    public function testGetPathRejectsMissingInstallDirectory(): void
    {
        $installationManager = $this->createMock(InstallationManager::class);
        $installationManager
            ->expects(self::once())
            ->method('getInstallPath')
            ->willReturn($this->cwd . '/missing');

        $assetManager = $this->createMock(AssetManagerInterface::class);
        $assetManager->expects(self::never())->method('getPackageName');

        $package = $this->createMock(PackageInterface::class);
        $package->method('getExtra')->willReturn(['foxy' => true]);
        $package->method('getRequires')->willReturn([]);
        $package->method('getDevRequires')->willReturn([]);

        self::assertNull(AssetUtil::getPath($installationManager, $assetManager, $package));
    }

    /**
     * @throws JsonException
     */
    public function testGetPathRejectsRootPackageDirectoryTraversal(): void
    {
        $installPath = $this->cwd . '/install';
        $outsidePath = $this->cwd . '/outside';
        $this->sfs->mkdir([$installPath, $outsidePath]);
        file_put_contents(
            $installPath . '/composer.json',
            '{"config":{"foxy":{"root-package-json-dir":"../outside"}}}',
        );
        file_put_contents($outsidePath . '/package.json', '{}');

        $installationManager = $this->createMock(InstallationManager::class);
        $installationManager->expects(self::once())->method('getInstallPath')->willReturn($installPath);

        $assetManager = $this->createMock(AssetManagerInterface::class);
        $assetManager->expects(self::once())->method('getPackageName')->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);
        $package->method('getExtra')->willReturn(['foxy' => true]);
        $package->method('getRequires')->willReturn([]);
        $package->method('getDevRequires')->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes its Composer install directory');

        AssetUtil::getPath($installationManager, $assetManager, $package);
    }

    public function testGetPathThrowsWhenComposerMetadataCannotBeRead(): void
    {
        $installPath = $this->cwd . '/unreadable-composer-package';

        $this->sfs->mkdir($installPath);
        file_put_contents($installPath . '/composer.json', '{}');

        $installRoot = realpath($installPath);

        if (false === $installRoot) {
            self::fail('Unable to resolve the fixture installation directory.');
        }

        $composerJsonPath = $installRoot . '/composer.json';

        $installationManager = $this->createMock(InstallationManager::class);
        $installationManager->expects(self::once())->method('getInstallPath')->willReturn($installPath);

        $assetManager = $this->createMock(AssetManagerInterface::class);
        $assetManager->expects(self::once())->method('getPackageName')->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);
        $package->method('getExtra')->willReturn(['foxy' => true]);
        $package->method('getRequires')->willReturn([]);
        $package->method('getDevRequires')->willReturn([]);

        MockerState::addCondition(
            'Foxy\\Util',
            'file_get_contents',
            [$composerJsonPath, false, null, 0, null],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read Composer package file');

        AssetUtil::getPath($installationManager, $assetManager, $package);
    }

    /**
     * @throws JsonException
     */
    #[DataProvider('getExtraData')]
    public function testGetPathWithExtraActivation(bool $withExtra, bool $fileExists = false): void
    {
        $installationManager = $this->createMock(InstallationManager::class);

        if ($withExtra && $fileExists) {
            $installationManager->expects(self::once())->method('getInstallPath')->willReturn($this->cwd);
        }

        $assetManager = $this
            ->getMockBuilder(AbstractAssetManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $assetManager->method('getPackageName')->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);

        $package->expects(self::any())->method('getRequires')->willReturn([]);
        $package->expects(self::any())->method('getDevRequires')->willReturn([]);
        $package->expects(self::atLeastOnce())->method('getExtra')->willReturn(['foxy' => $withExtra]);

        if ($fileExists) {
            $expectedFilename = $this->cwd . DIRECTORY_SEPARATOR . $assetManager->getPackageName();

            file_put_contents($expectedFilename, '{}');

            $expectedFilename = $withExtra ? str_replace('\\', '/', realpath($expectedFilename)) : null;
        } else {
            $expectedFilename = null;
        }

        $res = AssetUtil::getPath($installationManager, $assetManager, $package);

        self::assertSame($expectedFilename, $res);
    }

    /**
     * @throws JsonException
     */
    public function testGetPathWithoutRequiredFoxy(): void
    {
        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager->expects(self::never())->method('getInstallPath');

        $assetManager = $this->createMock(AbstractAssetManager::class);

        $package = $this->createMock(PackageInterface::class);

        $package->expects(self::once())->method('getRequires')->willReturn([]);
        $package->expects(self::once())->method('getDevRequires')->willReturn([]);

        $res = AssetUtil::getPath($installationManager, $assetManager, $package);

        self::assertNull($res);
    }

    /**
     * @param Link[] $requires
     * @param Link[] $devRequires
     *
     * @throws JsonException
     */
    #[DataProvider('getRequiresData')]
    public function testGetPathWithRequiredFoxy(array $requires, array $devRequires, bool $fileExists = false): void
    {
        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager->expects(self::once())->method('getInstallPath')->willReturn($this->cwd);

        $assetManager = $this
            ->getMockBuilder(AbstractAssetManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $assetManager->method('getPackageName')->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);

        $package->expects(self::once())->method('getRequires')->willReturn($requires);

        if (0 === count($devRequires)) {
            $package->expects(self::never())->method('getDevRequires');
        } else {
            $package->expects(self::once())->method('getDevRequires')->willReturn($devRequires);
        }

        if ($fileExists) {
            $expectedFilename = $this->cwd . DIRECTORY_SEPARATOR . $assetManager->getPackageName();

            file_put_contents($expectedFilename, '{}');

            $expectedFilename = str_replace('\\', '/', realpath($expectedFilename));
        } else {
            $expectedFilename = null;
        }

        $res = AssetUtil::getPath($installationManager, $assetManager, $package);

        self::assertSame($expectedFilename, $res);
    }

    /**
     * @throws JsonException
     */
    public function testGetPathWithRootPackageDir(): void
    {
        $installationManager = $this->createMock(InstallationManager::class);
        $installationManager
            ->expects(self::once())
            ->method('getInstallPath')
            ->willReturn('tests/Fixtures/package/global');

        $assetManager = $this->createMock(AssetManagerInterface::class);
        $assetManager->expects(self::once())->method('getPackageName')->willReturn('foo/bar/package.json');

        $package = $this->createMock(PackageInterface::class);
        $package->expects(self::once())->method('getName')->willReturn('foo/bar');
        $package->expects(self::once())->method('getRequires')->willReturn([]);
        $package->expects(self::once())->method('getDevRequires')->willReturn([]);

        $configPackages = [
            '/^foo\/bar$/' => true,
        ];

        $expectedPath = 'tests/Fixtures/package/global/theme/foo/bar/package.json';

        $res = AssetUtil::getPath($installationManager, $assetManager, $package, $configPackages);

        self::assertStringContainsString($expectedPath, $res);
    }

    public function testHasExtraActivation(): void
    {
        $package = $this->createMock(PackageInterface::class);
        $package->expects(self::once())->method('getExtra')->willReturn(['foxy' => true]);

        self::assertTrue(AssetUtil::hasExtraActivation($package));
    }

    public function testHasNoPluginDependency(): void
    {
        self::assertFalse(
            AssetUtil::hasPluginDependency([new Link('root/package', 'foo/bar', new Constraint('=', '1.0.0'))]),
        );
    }

    public function testHasPluginDependency(): void
    {
        self::assertTrue(
            AssetUtil::hasPluginDependency(
                [
                    new Link('root/package', 'foo/bar', new Constraint('=', '1.0.0')),
                    new Link('root/package', 'php-forge/foxy', new Constraint('=', '1.0.0')),
                    new Link('root/package', 'bar/foo', new Constraint('=', '1.0.0')),
                ],
            ),
        );
    }

    public function testIsAsset(): void
    {
        $package = $this->createMock(PackageInterface::class);
        $package->expects(self::once())->method('getName')->willReturn('foo/bar');
        $package->expects(self::once())->method('getExtra')->willReturn([]);
        $package->expects(self::once())->method('getRequires')->willReturn([]);
        $package->expects(self::once())->method('getDevRequires')->willReturn([]);

        self::assertTrue(AssetUtil::isAsset($package, ['foo/bar' => true]));
    }

    #[DataProvider('getIsProjectActivationData')]
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

        $package = $this->createMock(PackageInterface::class);

        $package->expects(self::once())->method('getName')->willReturn($packageName);

        $res = AssetUtil::isProjectActivation($package, $enablePackages);

        self::assertSame($expected, $res);
    }

    #[DataProvider('getIsProjectActivationWithWildcardData')]
    public function testIsProjectActivationWithWildcardPattern(string $packageName, bool $expected): void
    {
        $enablePackages = [
            'baz/foo*' => false,
            'full-disable/qualified' => false,
            '*' => true,
        ];

        $package = $this->createMock(PackageInterface::class);

        $package->expects(self::once())->method('getName')->willReturn($packageName);

        $res = AssetUtil::isProjectActivation($package, $enablePackages);

        self::assertSame($expected, $res);
    }

    public function testProjectActivationRejectsStringValueForNamedPattern(): void
    {
        $package = $this->createMock(PackageInterface::class);
        $package->expects(self::once())->method('getName')->willReturn('foo/bar');

        self::assertFalse(AssetUtil::isProjectActivation($package, ['foo/bar' => 'foo/bar']));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->cwd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_asset_util_test_', true);
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
}
