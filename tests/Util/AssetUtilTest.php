<?php

declare(strict_types=1);

namespace Foxy\Tests\Util;

use Composer\Installer\InstallationManager;
use Composer\Package\{Link, PackageInterface};
use Composer\Semver\Constraint\Constraint;
use Foxy\Asset\{AbstractAssetManager, AssetManagerInterface};
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Tests\Provider\AssetUtilProvider;
use Foxy\Util\AssetUtil;
use JsonException;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Xepozz\InternalMocker\MockerState;

use function count;
use function file_put_contents;
use function ltrim;
use function realpath;
use function str_replace;
use function strtr;

use const DIRECTORY_SEPARATOR;

/**
 * Unit tests for {@see AssetUtil} asset package formatting, asset path resolution, and project activation.
 *
 * {@see AssetUtilProvider} for test case data providers.
 */
final class AssetUtilTest extends TestCase
{
    private string|null $cwd;
    private Filesystem|null $sfs;

    #[DataProviderExternal(AssetUtilProvider::class, 'packageVersions')]
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

            $package
                ->expects(self::never())
                ->method('getPrettyVersion');
            $package
                ->expects(self::never())
                ->method('getExtra');
        } else {
            $extra = [];

            if (null !== $branchAlias) {
                $extra['branch-alias'][$packageVersion] = $branchAlias;
            }

            $package
                ->expects(self::once())
                ->method('getPrettyVersion')
                ->willReturn($packageVersion);
            $package
                ->expects(self::once())
                ->method('getExtra')
                ->willReturn($extra);
        }

        $expected = ['name' => $packageName, 'version' => $expectedAssetVersion];

        $res = AssetUtil::formatPackage($package, $packageName, $assetPackage);

        self::assertEquals(
            $expected,
            $res,
            'Formatted package should match the expected asset version',
        );
    }

    public function testFormatPackageIgnoresBranchAliasForTaggedVersion(): void
    {
        $package = $this->createMock(PackageInterface::class);

        $package
            ->expects(self::once())
            ->method('getPrettyVersion')
            ->willReturn('1.2.3');
        $package
            ->expects(self::once())
            ->method('getExtra')
            ->willReturn(['branch-alias' => ['1.2.3' => '9.9.x-dev']]);

        self::assertSame(
            ['name' => '@composer-asset/foo--bar', 'version' => '1.2.3'],
            AssetUtil::formatPackage($package, '@composer-asset/foo--bar', []),
            'Formatted package should match the expected asset version',
        );
    }

    public function testFormatPackageStripsExecutableAndUnneededMetadata(): void
    {
        $package = $this->createMock(PackageInterface::class);

        $package
            ->method('getPrettyVersion')
            ->willReturn('1.2.3');
        $package
            ->method('getExtra')
            ->willReturn([]);

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

        $package
            ->expects(self::once())
            ->method('getName')
            ->willReturn('foo/bar');

        self::assertSame(
            '@composer-asset/foo--bar',
            AssetUtil::getName($package),
            'Package name should be formatted as a composer-asset name',
        );
    }

    /**
     * @throws JsonException
     */
    public function testGetPathAcceptsInstallPathWithTrailingSeparator(): void
    {
        $installPath = "{$this->cwd}/trailing-separator-package";

        $this->sfs->mkdir($installPath);

        file_put_contents("{$installPath}/package.json", '{}');

        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager
            ->expects(self::once())
            ->method('getInstallPath')
            ->willReturn($installPath . DIRECTORY_SEPARATOR);

        $assetManager = $this->createMock(AssetManagerInterface::class);

        $assetManager
            ->expects(self::once())
            ->method('getPackageName')
            ->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(['foxy' => true]);
        $package
            ->method('getRequires')
            ->willReturn([]);
        $package
            ->method('getDevRequires')
            ->willReturn([]);

        self::assertSame(
            str_replace('\\', '/', (string) realpath($installPath . '/package.json')),
            AssetUtil::getPath($installationManager, $assetManager, $package),
            'Asset path should be resolved correctly even with a trailing separator',
        );
    }

    /**
     * @throws JsonException
     */
    public function testGetPathAcceptsManifestWithinFilesystemRoot(): void
    {
        if ('/' !== DIRECTORY_SEPARATOR) {
            self::markTestSkipped(
                'This filesystem-root regression is specific to Unix paths.',
            );
        }

        $manifest = "{$this->cwd}/root-install-package.json";

        file_put_contents($manifest, '{}');

        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager
            ->expects(self::once())
            ->method('getInstallPath')
            ->willReturn(DIRECTORY_SEPARATOR);

        $assetManager = $this->createMock(AssetManagerInterface::class);

        $assetManager
            ->expects(self::once())
            ->method('getPackageName')
            ->willReturn(ltrim($manifest, '/'));

        $package = $this->createMock(PackageInterface::class);

        $package
            ->method('getName')
            ->willReturn('root/package');
        $package
            ->method('getExtra')
            ->willReturn(['foxy' => true]);
        $package
            ->method('getRequires')
            ->willReturn([]);
        $package
            ->method('getDevRequires')
            ->willReturn([]);

        self::assertSame(
            str_replace('\\', '/', (string) realpath($manifest)),
            AssetUtil::getPath($installationManager, $assetManager, $package),
            'Asset path should be resolved correctly when the manifest is within the filesystem root',
        );
    }

    /**
     * @throws JsonException
     */
    public function testGetPathIgnoresNonStringConfiguredDirectory(): void
    {
        $installPath = "{$this->cwd}/invalid-configured-package";

        $this->sfs->mkdir($installPath);

        file_put_contents(
            "{$installPath}/composer.json",
            '{"config":{"foxy":{"root-package-json-dir":[]}}}',
        );
        file_put_contents(
            "{$installPath}/package.json",
            '{}',
        );

        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager
            ->expects(self::once())
            ->method('getInstallPath')
            ->willReturn($installPath);

        $assetManager = $this->createMock(AssetManagerInterface::class);

        $assetManager
            ->expects(self::once())
            ->method('getPackageName')
            ->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(['foxy' => true]);
        $package
            ->method('getRequires')
            ->willReturn([]);
        $package
            ->method('getDevRequires')
            ->willReturn([]);

        self::assertSame(
            str_replace('\\', '/', (string) realpath($installPath . '/package.json')),
            AssetUtil::getPath($installationManager, $assetManager, $package),
            'Asset path should be resolved correctly even when the configured directory is not a string',
        );
    }

    /**
     * @throws JsonException
     */
    public function testGetPathPrefersConfiguredManifestOverRootManifest(): void
    {
        $installPath = "{$this->cwd}/configured-package";
        $configuredDirectory = "{$installPath}/resources";

        $this->sfs->mkdir($configuredDirectory);

        file_put_contents(
            "{$installPath}/composer.json",
            '{"config":{"foxy":{"root-package-json-dir":"resources"}}}',
        );
        file_put_contents(
            "{$installPath}/package.json",
            '{"source":"root"}',
        );
        file_put_contents(
            "{$configuredDirectory}/package.json",
            '{"source":"configured"}',
        );

        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager
            ->expects(self::once())
            ->method('getInstallPath')
            ->willReturn($installPath);

        $assetManager = $this->createMock(AssetManagerInterface::class);

        $assetManager
            ->expects(self::once())
            ->method('getPackageName')
            ->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(['foxy' => true]);
        $package
            ->method('getRequires')
            ->willReturn([]);
        $package
            ->method('getDevRequires')
            ->willReturn([]);

        self::assertSame(
            str_replace('\\', '/', (string) realpath($configuredDirectory . '/package.json')),
            AssetUtil::getPath($installationManager, $assetManager, $package),
            'Configured manifest should be preferred over root manifest.',
        );
    }

    /**
     * @throws JsonException
     */
    public function testGetPathRejectsInvalidComposerMetadataEvenWhenRootManifestExists(): void
    {
        $installPath = "{$this->cwd}/direct-package";

        $this->sfs->mkdir($installPath);

        file_put_contents("{$installPath}/composer.json", 'invalid json');
        file_put_contents("{$installPath}/package.json", '{}');

        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager
            ->expects(self::once())
            ->method('getInstallPath')
            ->willReturn($installPath);

        $assetManager = $this->createMock(AssetManagerInterface::class);

        $assetManager
            ->expects(self::once())
            ->method('getPackageName')
            ->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(['foxy' => true]);
        $package
            ->method('getRequires')
            ->willReturn([]);
        $package
            ->method('getDevRequires')
            ->willReturn([]);

        $this->expectException(JsonException::class);

        AssetUtil::getPath($installationManager, $assetManager, $package);
    }

    public function testGetPathRejectsMissingInstallDirectory(): void
    {
        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager
            ->expects(self::once())
            ->method('getInstallPath')
            ->willReturn("{$this->cwd}/missing");

        $assetManager = $this->createMock(AssetManagerInterface::class);

        $assetManager
            ->expects(self::never())
            ->method('getPackageName');

        $package = $this->createMock(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(['foxy' => true]);
        $package
            ->method('getRequires')
            ->willReturn([]);
        $package
            ->method('getDevRequires')
            ->willReturn([]);

        self::assertNull(
            AssetUtil::getPath($installationManager, $assetManager, $package),
            "Expected 'null' when install directory is missing",
        );
    }

    /**
     * @throws JsonException
     */
    public function testGetPathRejectsRootPackageDirectoryTraversal(): void
    {
        $installPath = "{$this->cwd}/install";
        $outsidePath = "{$this->cwd}/outside";

        $this->sfs->mkdir([$installPath, $outsidePath]);

        file_put_contents(
            "{$installPath}/composer.json",
            '{"config":{"foxy":{"root-package-json-dir":"../outside"}}}',
        );
        file_put_contents("{$outsidePath}/package.json", '{}');

        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager
            ->expects(self::once())
            ->method('getInstallPath')
            ->willReturn($installPath);

        $assetManager = $this->createMock(AssetManagerInterface::class);

        $assetManager
            ->expects(self::once())
            ->method('getPackageName')
            ->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(['foxy' => true]);
        $package
            ->method('getRequires')
            ->willReturn([]);
        $package
            ->method('getDevRequires')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::UTIL_ASSET_PACKAGE_PATH_ESCAPES->getMessage(
                strtr((string) realpath("{$outsidePath}/package.json"), '\\', '/'),
            ),
        );

        AssetUtil::getPath($installationManager, $assetManager, $package);
    }

    public function testGetPathThrowsWhenComposerMetadataCannotBeRead(): void
    {
        $installPath = "{$this->cwd}/unreadable-composer-package";

        $this->sfs->mkdir($installPath);

        file_put_contents("{$installPath}/composer.json", '{}');

        $installRoot = realpath($installPath);

        if (false === $installRoot) {
            self::fail('Unable to resolve the fixture installation directory.');
        }

        $composerJsonPath = $installRoot . '/composer.json';

        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager
            ->expects(self::once())
            ->method('getInstallPath')
            ->willReturn($installPath);

        $assetManager = $this->createMock(AssetManagerInterface::class);

        $assetManager
            ->expects(self::once())
            ->method('getPackageName')
            ->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(['foxy' => true]);
        $package
            ->method('getRequires')
            ->willReturn([]);
        $package
            ->method('getDevRequires')
            ->willReturn([]);

        MockerState::addCondition(
            'Foxy\\Util',
            'file_get_contents',
            [$composerJsonPath, false, null, 0, null],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::UTIL_COMPOSER_PACKAGE_FILE_UNREADABLE->getMessage($composerJsonPath),
        );

        AssetUtil::getPath($installationManager, $assetManager, $package);
    }

    /**
     * @throws JsonException
     */
    #[DataProviderExternal(AssetUtilProvider::class, 'extraActivations')]
    public function testGetPathWithExtraActivation(bool $withExtra, bool $fileExists = false): void
    {
        $installationManager = $this->createMock(InstallationManager::class);

        if ($withExtra && $fileExists) {
            $installationManager
                ->expects(self::once())
                ->method('getInstallPath')
                ->willReturn($this->cwd);
        }

        $assetManager = $this
            ->getMockBuilder(AbstractAssetManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $assetManager
            ->method('getPackageName')
            ->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);

        $package
            ->expects(self::any())
            ->method('getRequires')
            ->willReturn([]);
        $package
            ->expects(self::any())
            ->method('getDevRequires')
            ->willReturn([]);
        $package
            ->expects(self::atLeastOnce())
            ->method('getExtra')
            ->willReturn(['foxy' => $withExtra]);

        if ($fileExists) {
            $expectedFilename = $this->cwd . DIRECTORY_SEPARATOR . $assetManager->getPackageName();

            file_put_contents($expectedFilename, '{}');

            $expectedFilename = $withExtra ? str_replace('\\', '/', realpath($expectedFilename)) : null;
        } else {
            $expectedFilename = null;
        }

        $res = AssetUtil::getPath($installationManager, $assetManager, $package);

        self::assertSame(
            $expectedFilename,
            $res,
            'The returned path does not match the expected path.',
        );
    }

    /**
     * @throws JsonException
     */
    public function testGetPathWithoutRequiredFoxy(): void
    {
        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager
            ->expects(self::never())
            ->method('getInstallPath');

        $assetManager = $this->createMock(AbstractAssetManager::class);
        $package = $this->createMock(PackageInterface::class);

        $package
            ->expects(self::once())
            ->method('getRequires')
            ->willReturn([]);
        $package
            ->expects(self::once())
            ->method('getDevRequires')
            ->willReturn([]);

        $res = AssetUtil::getPath($installationManager, $assetManager, $package);

        self::assertNull(
            $res,
            'The returned path should be null when Foxy is not required.',
        );
    }

    /**
     * @param Link[] $requires
     * @param Link[] $devRequires
     *
     * @throws JsonException
     */
    #[DataProviderExternal(AssetUtilProvider::class, 'foxyRequirements')]
    public function testGetPathWithRequiredFoxy(array $requires, array $devRequires, bool $fileExists = false): void
    {
        $installationManager = $this->createMock(InstallationManager::class);

        $installationManager
            ->expects(self::once())
            ->method('getInstallPath')
            ->willReturn($this->cwd);
        $assetManager = $this
            ->getMockBuilder(AbstractAssetManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $assetManager
            ->method('getPackageName')
            ->willReturn('package.json');

        $package = $this->createMock(PackageInterface::class);

        $package
            ->expects(self::once())
            ->method('getRequires')
            ->willReturn($requires);

        if (0 === count($devRequires)) {
            $package
                ->expects(self::never())
                ->method('getDevRequires');
        } else {
            $package
                ->expects(self::once())
                ->method('getDevRequires')
                ->willReturn($devRequires);
        }

        if ($fileExists) {
            $expectedFilename = $this->cwd . DIRECTORY_SEPARATOR . $assetManager->getPackageName();

            file_put_contents($expectedFilename, '{}');

            $expectedFilename = str_replace('\\', '/', realpath($expectedFilename));
        } else {
            $expectedFilename = null;
        }

        $res = AssetUtil::getPath($installationManager, $assetManager, $package);

        self::assertSame(
            $expectedFilename,
            $res,
            'The expected path does not match the result.',
        );
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

        $assetManager
            ->expects(self::once())
            ->method('getPackageName')
            ->willReturn('foo/bar/package.json');

        $package = $this->createMock(PackageInterface::class);

        $package
            ->expects(self::once())
            ->method('getName')
            ->willReturn('foo/bar');
        $package
            ->expects(self::once())
            ->method('getRequires')
            ->willReturn([]);
        $package
            ->expects(self::once())
            ->method('getDevRequires')
            ->willReturn([]);

        $configPackages = ['/^foo\/bar$/' => true];

        $expectedPath = 'tests/Fixtures/package/global/theme/foo/bar/package.json';

        $res = AssetUtil::getPath($installationManager, $assetManager, $package, $configPackages);

        self::assertStringContainsString(
            $expectedPath,
            $res,
            'The expected path does not match the result.',
        );
    }

    public function testHasExtraActivation(): void
    {
        $package = $this->createMock(PackageInterface::class);

        $package
            ->expects(self::once())
            ->method('getExtra')
            ->willReturn(['foxy' => true]);

        self::assertTrue(
            AssetUtil::hasExtraActivation($package),
            'The package should have extra activation.',
        );
    }

    public function testHasNoPluginDependency(): void
    {
        self::assertFalse(
            AssetUtil::hasPluginDependency([new Link('root/package', 'foo/bar', new Constraint('=', '1.0.0'))]),
            'The package should not have the plugin dependency.',
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
            'The package should have the plugin dependency.',
        );
    }

    public function testIsAsset(): void
    {
        $package = $this->createMock(PackageInterface::class);

        $package
            ->expects(self::once())
            ->method('getName')
            ->willReturn('foo/bar');
        $package
            ->expects(self::once())
            ->method('getExtra')
            ->willReturn([]);
        $package
            ->expects(self::once())
            ->method('getRequires')
            ->willReturn([]);
        $package
            ->expects(self::once())
            ->method('getDevRequires')
            ->willReturn([]);

        self::assertTrue(
            AssetUtil::isAsset($package, ['foo/bar' => true]),
            'The package should be recognized as an asset.',
        );
    }

    public function testIsAssetIgnoresExtraActivationWhenProjectConfigDisablesPackage(): void
    {
        $package = $this->createMock(PackageInterface::class);

        $package
            ->expects(self::once())
            ->method('getName')
            ->willReturn('foo/bar');
        $package
            ->expects(self::never())
            ->method('getExtra');
        $package
            ->expects(self::never())
            ->method('getRequires');
        $package
            ->expects(self::never())
            ->method('getDevRequires');

        self::assertFalse(
            AssetUtil::isAsset($package, ['foo/bar' => false]),
            'Disabled package must not be an asset.',
        );
    }

    #[DataProviderExternal(AssetUtilProvider::class, 'projectActivations')]
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

        $package
            ->expects(self::once())
            ->method('getName')
            ->willReturn($packageName);

        $res = AssetUtil::isProjectActivation($package, $enablePackages);

        self::assertSame(
            $expected,
            $res,
            'The project activation status did not match the expected value.',
        );
    }

    #[DataProviderExternal(AssetUtilProvider::class, 'wildcardProjectActivations')]
    public function testIsProjectActivationWithWildcardPattern(string $packageName, bool $expected): void
    {
        $enablePackages = [
            'baz/foo*' => false,
            'full-disable/qualified' => false,
            '*' => true,
        ];

        $package = $this->createMock(PackageInterface::class);

        $package
            ->expects(self::once())
            ->method('getName')
            ->willReturn($packageName);

        $res = AssetUtil::isProjectActivation($package, $enablePackages);

        self::assertSame(
            $expected,
            $res,
            'The project activation status did not match the expected value.',
        );
    }

    public function testProjectActivationRejectsStringValueForNamedPattern(): void
    {
        $package = $this->createMock(PackageInterface::class);

        $package
            ->expects(self::once())
            ->method('getName')
            ->willReturn('foo/bar');

        self::assertFalse(
            AssetUtil::isProjectActivation($package, ['foo/bar' => 'foo/bar']),
            'The project activation status did not match the expected value.',
        );
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
