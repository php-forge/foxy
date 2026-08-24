<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Foxy\Config\Config;
use Foxy\Converter\VersionConverterInterface;
use Foxy\Fallback\FallbackInterface;
use Foxy\Tests\Fixtures\Asset\InspectableAssetManager;
use Foxy\Tests\Fixtures\Util\ProcessExecutorMock;
use PHPUnit\Framework\Attributes\{DataProvider, PreserveGlobalState, RunInSeparateProcess};
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Seld\JsonLint\ParsingException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Xepozz\InternalMocker\MockerState;

use function chdir;
use function define;
use function defined;
use function file_put_contents;
use function getcwd;
use function str_replace;

use const DIRECTORY_SEPARATOR;

final class AbstractAssetManagerTest extends TestCase
{
    private Config|null $config = null;
    private string|null $cwd = null;
    private ProcessExecutorMock|null $executor = null;
    private FallbackInterface|MockObject|null $fallback = null;
    private Filesystem|MockObject|null $fs = null;
    private IOInterface|MockObject|null $io = null;
    private string|null $oldCwd = null;
    private RootPackageInterface|MockObject|null $rootPackage = null;
    private SymfonyFilesystem|null $sfs = null;

    public static function relativeRootPackageDirectories(): array
    {
        return [
            ['prefixC:', 'prefixC:'],
            ['C:directory', 'C:directory'],
            ['relative/C:/directory', 'relative/C:/directory'],
        ];
    }

    public function testActionHookRemainsExtensible(): void
    {
        $this->io
            ->expects(self::once())
            ->method('write')
            ->with('<info>Merging Composer dependencies in the asset package</info>');

        $manager = $this->createManager();
        $manager->addDependencies(
            $this->rootPackage,
            ['@composer-asset/foo--bar' => 'path/foo/bar/package.json'],
        );

        self::assertSame([], $manager->getHandledDependencies());
    }

    public function testAddDependenciesPropagatesFailureWithoutFallback(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', 'invalid json');

        $manager = new InspectableAssetManager(
            $this->io,
            $this->config,
            $this->executor,
            $this->fs,
        );

        $this->expectException(ParsingException::class);

        $manager->addDependencies($this->rootPackage, []);
    }

    public function testAddDependenciesRestoresFallbackAfterFailure(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', 'invalid json');

        $this->fallback->expects(self::once())->method('restore');
        $this->expectException(ParsingException::class);

        $this->createManager()->addDependencies($this->rootPackage, []);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBuildCommandNormalizesWindowsBinaryPath(): void
    {
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            define('PHP_WINDOWS_VERSION_BUILD', 1);
        }

        $this->config = new Config(['manager-bin' => 'C:/tools/inspectable.exe']);

        self::assertSame(
            'C:\\tools\\inspectable.exe install',
            $this->createManager()->buildCommandForTest('inspectable.exe', 'install', 'install'),
        );
    }

    public function testBuildCommandUsesTrimmedGlobalAndActionOptions(): void
    {
        $this->config = new Config(
            [
                'manager-bin' => 'custom/bin',
                'manager-options' => '  --global  ',
                'manager-install-options' => '  --local  ',
            ],
        );

        self::assertSame(
            str_replace('/', DIRECTORY_SEPARATOR, 'custom/bin') . ' install --global --local',
            $this->createManager()->buildCommandForTest('inspectable', 'install', 'install'),
        );
    }

    public function testInjectedVersionConverterReceivesTrimmedVersion(): void
    {
        $converter = $this->createMock(VersionConverterInterface::class);
        $converter
            ->expects(self::once())
            ->method('convertVersion')
            ->with('custom-version')
            ->willReturn('42.0.0');
        $this->executor->addExpectedValues(0, "  custom-version\n");

        self::assertTrue($this->createManager($converter)->isAvailable());
    }

    public function testIsAvailableRejectsWhitespaceOnlyVersion(): void
    {
        $converter = $this->createMock(VersionConverterInterface::class);
        $converter->expects(self::never())->method('convertVersion');
        $this->executor->addExpectedValues(0, " \n\t");

        self::assertFalse($this->createManager($converter)->isAvailable());
    }

    public function testIsAvailableResolvesManagerSpecificConfiguration(): void
    {
        $this->config = new Config([], ['manager-version' => ['inspectable' => '>=42.0.0']]);
        $this->executor->addExpectedValues(0, '42.0.0');

        self::assertNull($this->config->get('manager-version'));
        self::assertTrue($this->createManager()->isAvailable());
        self::assertSame('>=42.0.0', $this->config->get('manager-version'));
    }

    #[DataProvider('relativeRootPackageDirectories')]
    public function testRootPackageDirectoryOnlyRecognizesAnchoredAbsolutePaths(
        string $configuredDirectory,
        string $relativeDirectory,
    ): void {
        $this->config = new Config([], ['root-package-json-dir' => $configuredDirectory]);

        self::assertSame(
            $this->cwd . DIRECTORY_SEPARATOR . $relativeDirectory,
            $this->createManager()->getRootPackageDirForTest(),
        );
    }

    public function testRootPackageDirectoryRecognizesLeadingBackslashAsAbsolute(): void
    {
        $rootPackageDir = '\\server\\share';
        $this->config = new Config([], ['root-package-json-dir' => $rootPackageDir]);

        self::assertSame($rootPackageDir, $this->createManager()->getRootPackageDirForTest());
    }

    public function testRootPackageDirectoryRemovesTrailingSeparators(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => $this->cwd . '///']);

        self::assertSame($this->cwd, $this->createManager()->getRootPackageDirForTest());
    }

    public function testRootPackageDirectoryRestoresDriveRootSeparator(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => 'C:']);

        self::assertSame('C:' . DIRECTORY_SEPARATOR, $this->createManager()->getRootPackageDirForTest());
    }

    public function testRootPackageDirectoryTrimsCurrentDirectorySeparator(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => 'assets']);

        MockerState::addCondition('Foxy\\Asset', 'getcwd', [], DIRECTORY_SEPARATOR);

        self::assertSame(
            DIRECTORY_SEPARATOR . 'assets',
            $this->createManager()->getRootPackageDirForTest(),
        );
    }

    public function testRootPathsDoNotDuplicateDirectorySeparator(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => DIRECTORY_SEPARATOR]);
        $manager = $this->createManager();

        self::assertSame(DIRECTORY_SEPARATOR . 'inspectable.lock', $manager->getLockFilePathForTest());
        self::assertSame(DIRECTORY_SEPARATOR . 'node_modules', $manager->getNodeModulesPathForTest());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new Config([]);
        $this->io = $this->createMock(IOInterface::class);
        $this->executor = new ProcessExecutorMock($this->io);
        $this->fs = $this->createMock(Filesystem::class);
        $this->fallback = $this->createMock(FallbackInterface::class);
        $this->rootPackage = $this->createMock(RootPackageInterface::class);
        $this->rootPackage->method('getLicense')->willReturn([]);
        $this->sfs = new SymfonyFilesystem();
        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_abstract_asset_manager_test_', true);
        $this->sfs->mkdir($this->cwd);

        chdir($this->cwd);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);
        $this->sfs->remove($this->cwd);
        $this->config = null;
        $this->cwd = null;
        $this->executor = null;
        $this->fallback = null;
        $this->fs = null;
        $this->io = null;
        $this->oldCwd = null;
        $this->rootPackage = null;
        $this->sfs = null;
    }

    private function createManager(
        VersionConverterInterface|null $versionConverter = null,
    ): InspectableAssetManager {
        return new InspectableAssetManager(
            $this->io,
            $this->config,
            $this->executor,
            $this->fs,
            $this->fallback,
            $versionConverter,
        );
    }
}
