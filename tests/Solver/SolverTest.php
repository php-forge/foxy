<?php

declare(strict_types=1);

namespace Foxy\Tests\Solver;

use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\{Link, PackageInterface, RootPackageInterface};
use Composer\Repository\{InstalledArrayRepository, RepositoryManager, WritableRepositoryInterface};
use Composer\Semver\Constraint\Constraint;
use Composer\Util\{Filesystem, HttpDownloader};
use Exception;
use Foxy\Asset\AssetManagerInterface;
use Foxy\Config\Config;
use Foxy\Event\{GetAssetsEvent, PostSolveEvent, PreSolveEvent};
use Foxy\Exception\RuntimeException;
use Foxy\Fallback\FallbackInterface;
use Foxy\FoxyEvents;
use Foxy\Solver\{Solver, SolverInterface};
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Xepozz\InternalMocker\MockerState;

use function chdir;
use function dirname;
use function file_put_contents;
use function realpath;
use function rtrim;
use function substr;

use const DIRECTORY_SEPARATOR;

class SolverTest extends TestCase
{
    private Composer|MockObject|null $composer = null;
    private \Composer\Config|MockObject|null $composerConfig = null;
    private FallbackInterface|MockObject|null $composerFallback = null;
    private Config|null $config = null;
    private string|null $cwd = '';
    private EventDispatcher|MockObject|null $dispatcher = null;
    private Filesystem|null $fs = null;
    private InstallationManager|MockObject|null $im = null;
    private IOInterface|MockObject|null $io = null;
    private MockObject|WritableRepositoryInterface|null $localRepo = null;
    private AssetManagerInterface|MockObject|null $manager = null;
    private string|null $oldCwd = '';
    private MockObject|RootPackageInterface|null $package = null;
    private \Symfony\Component\Filesystem\Filesystem|MockObject|null $sfs = null;
    private SolverInterface|null $solver = null;
    private string $vendorDir = '';

    public static function getSolveData(): array
    {
        return [[0], [1]];
    }

    public function testCanonicalizePathPreservesSingleFilesystemRootSeparator(): void
    {
        $root = $this->getFilesystemRoot();
        $relativePath = uniqid('foxy_nonexistent_path_', true);
        $path = $root . $relativePath;
        $method = (new ReflectionClass(Solver::class))->getMethod('canonicalizePath');

        self::assertSame($path, $method->invoke($this->solver, $path, $root));
        self::assertSame($path, $method->invoke($this->solver, $relativePath, $root));
    }

    public function testCanonicalizePathStopsAtFilesystemRootWhenExistenceCheckFails(): void
    {
        $root = $this->getFilesystemRoot();

        MockerState::addCondition('Foxy\\Solver', 'file_exists', [$root], false);
        MockerState::addCondition('Foxy\\Solver', 'is_link', [$root], false);

        self::assertSame(
            $root,
            $this->invokeSolverMethod('canonicalizePath', $root, $root),
        );
    }

    public function testGetMockPackagePathDoesNotCopySourceBeforeWritingFormattedManifest(): void
    {
        $assetDir = "{$this->cwd}/direct-write-assets";
        $source = "{$this->cwd}/source-package.json";

        $target = "{$assetDir}/foo/bar/source-package.json";

        $sourceContent = <<<JSON
            {
                "name": "source-package",
                "version": "1.2.3",
                "engines": {},
                "bundleDependencies": [],
                "scripts": {
                    "build": "ignored"
                },
                "dependencies": {}
            }
            JSON;

        $package = $this->createMock(PackageInterface::class);
        $package->method('getName')->willReturn('foo/bar');

        file_put_contents($source, $sourceContent);

        $fs = $this->getMockBuilder(Filesystem::class)->onlyMethods(['copy'])->getMock();
        $fs->expects(self::never())->method('copy');
        $solver = new Solver($this->manager, $this->config, $fs, $this->composerFallback);

        $result = $this->invokeSolverMethodOn($solver, 'getMockPackagePath', $package, $assetDir, $source);

        self::assertSame(['@composer-asset/foo--bar', $target], $result);
        self::assertSame($sourceContent, file_get_contents($source));
        self::assertFileExists($target);
        self::assertSame(
            <<<'JSON'
                {
                    "name": "@composer-asset/foo--bar",
                    "version": "1.2.3",
                    "engines": {},
                    "bundleDependencies": [],
                    "dependencies": {}
                }

                JSON,
            file_get_contents($target),
        );
    }

    public function testGetMockPackagePathRejectsUnreadableSourceManifest(): void
    {
        $assetDir = "{$this->cwd}/unreadable-source-assets";
        $source = "{$this->cwd}/source-package.json";

        $package = $this->createMock(PackageInterface::class);
        $package->method('getName')->willReturn('foo/bar');

        MockerState::addCondition(
            'Foxy\\Solver',
            'file_get_contents',
            [$source, false, null, 0, null],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Unable to read asset manifest "%s".', $source));

        $this->invokeSolverMethod('getMockPackagePath', $package, $assetDir, $source);
    }

    public function testGetMockPackagePathWrapsDirectoryCreationFailure(): void
    {
        $assetDir = $this->cwd . '/directory-failure-assets';
        $source = $this->cwd . '/source-package.json';
        $packagePath = $assetDir . '/foo/bar';
        $package = $this->createMock(PackageInterface::class);
        $package->method('getName')->willReturn('foo/bar');
        file_put_contents($source, '{}');

        $failure = new \RuntimeException('Directory creation failed.');
        $fs = $this->getMockBuilder(Filesystem::class)->onlyMethods(['ensureDirectoryExists'])->getMock();
        $fs
            ->expects(self::once())
            ->method('ensureDirectoryExists')
            ->with($packagePath)
            ->willThrowException($failure);
        $solver = new Solver($this->manager, $this->config, $fs, $this->composerFallback);

        try {
            $this->invokeSolverMethodOn($solver, 'getMockPackagePath', $package, $assetDir, $source);
            self::fail('Expected directory creation to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame(sprintf('Unable to create asset directory "%s".', $packagePath), $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testIntegerOneEnablesSolver(): void
    {
        $this->addInstalledPackages();
        $assetDir = $this->cwd . '/integer-enabled-assets';
        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => 1, 'composer-asset-dir' => $assetDir]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::once())->method('addDependencies')->with($this->package, []);
        $this->manager->expects(self::once())->method('run')->willReturn(0);

        $solver->solve($this->composer, $this->io);

        self::assertFileExists($assetDir . '/.foxy-managed');
    }

    public function testPrepareAssetDirectoryRejectsSymbolicLinkRace(): void
    {
        $assetDir = $this->cwd . '/late-symlink-assets';

        MockerState::addCondition('Foxy\\Solver', 'is_link', [$assetDir], true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not be a symbolic link');

        $this->invokeSolverMethod('prepareAssetDirectory', $assetDir, $this->vendorDir);
    }

    public function testPrepareAssetDirectoryThrowsWhenDirectoryCannotBeInspected(): void
    {
        $assetDir = $this->cwd . '/unreadable-assets';
        $this->sfs->mkdir($assetDir);

        MockerState::addCondition('Foxy\\Solver', 'scandir', [$assetDir, SCANDIR_SORT_ASCENDING, null], false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to inspect Composer asset directory');

        $this->invokeSolverMethod('prepareAssetDirectory', $assetDir, $this->vendorDir);
    }

    public function testPrepareAssetDirectoryThrowsWhenMarkerCannotBeWritten(): void
    {
        $assetDir = $this->cwd . '/marker-failure-assets';
        $marker = $assetDir . '/.foxy-managed';

        MockerState::addCondition(
            'Foxy\\Solver',
            'file_put_contents',
            [$marker, "Managed by php-forge/foxy.\n", 0, null],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to mark Composer asset directory');

        $this->invokeSolverMethod('prepareAssetDirectory', $assetDir, $this->vendorDir);
    }

    public function testPrepareAssetDirectoryThrowsWhenProjectDirectoryIsUnavailable(): void
    {
        MockerState::addCondition('Foxy\\Solver', 'getcwd', [], false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to get the current working directory.');

        $this->invokeSolverMethod('prepareAssetDirectory', $this->cwd . '/assets', $this->vendorDir);
    }

    public function testPrepareAssetDirectoryWrapsDirectoryCreationFailure(): void
    {
        $assetDir = $this->cwd . '/creation-failure-assets';
        $failure = new \RuntimeException('Directory creation failed.');
        $fs = $this->getMockBuilder(Filesystem::class)->onlyMethods(['ensureDirectoryExists'])->getMock();
        $fs
            ->expects(self::once())
            ->method('ensureDirectoryExists')
            ->with($assetDir)
            ->willThrowException($failure);
        $solver = new Solver($this->manager, $this->config, $fs, $this->composerFallback);

        try {
            $this->invokeSolverMethodOn($solver, 'prepareAssetDirectory', $assetDir, $this->vendorDir);
            self::fail('Expected directory creation to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                sprintf('Unable to create Composer asset directory "%s".', $assetDir),
                $exception->getMessage(),
            );
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testSetUpdatable(): void
    {
        $this->manager->expects(self::once())->method('setUpdatable')->with(false);
        $this->solver->setUpdatable(false);
    }

    /**
     * @param int $resRunManager The result value of the run command of asset manager
     *
     * @throws Exception
     */
    #[DataProvider('getSolveData')]
    public function testSolve(int $resRunManager): void
    {
        $requirePackage = $this->createMock(PackageInterface::class);

        $requirePackage->expects(self::any())->method('getPrettyVersion')->willReturn('1.0.0');
        $requirePackage->expects(self::any())->method('getName')->willReturn('foo/bar');
        $requirePackage
            ->expects(self::any())
            ->method('getRequires')
            ->willReturn([new Link('root/package', 'php-forge/foxy', new Constraint('=', '1.0.0'))]);
        $requirePackage->expects(self::any())->method('getDevRequires')->willReturn([]);

        $this->addInstalledPackages([$requirePackage]);

        $requirePackagePath = $this->cwd . '/vendor/foo/bar';

        $this->im->expects(self::once())->method('getInstallPath')->willReturn($requirePackagePath);
        $this->manager->expects(self::exactly(2))->method('getPackageName')->willReturn('package.json');
        $mockPackageFilename = $this->getCanonicalPath('/composer-asset-dir/foo/bar/package.json');

        $this->manager
            ->expects(self::once())
            ->method('addDependencies')
            ->with(
                $this->package,
                ['@composer-asset/foo--bar' => $mockPackageFilename],
            );
        $this->manager->expects(self::once())->method('run')->willReturn($resRunManager);

        if (0 === $resRunManager) {
            $this->composerFallback->expects(self::never())->method('restore');
        } else {
            $this->composerFallback->expects(self::once())->method('restore');

            $this->expectException('RuntimeException');
            $this->expectExceptionMessage('The asset manager ended with error code 1');
        }

        $requirePackageFilename = $requirePackagePath . DIRECTORY_SEPARATOR . $this->manager->getPackageName();

        $this->sfs->mkdir(dirname($requirePackageFilename));

        file_put_contents($requirePackageFilename, '{}');

        $this->solver->solve($this->composer, $this->io);

        self::assertJsonStringEqualsJsonString(
            '{"name":"@composer-asset/foo--bar","version":"1.0.0"}',
            (string) file_get_contents($mockPackageFilename),
        );
    }

    public function testSolveAcceptsAlreadyRemovedDirectoryWhenFilesystemReportsFailure(): void
    {
        $this->addInstalledPackages();
        $assetDir = $this->cwd . '/already-removed-assets';
        $this->sfs->mkdir($assetDir);
        file_put_contents($assetDir . '/.foxy-managed', 'marker');

        $fs = $this->getMockBuilder(Filesystem::class)->onlyMethods(['remove'])->getMock();
        $fs
            ->expects(self::once())
            ->method('remove')
            ->with($this->getCanonicalPath('/already-removed-assets'))
            ->willReturnCallback(function (string $path): bool {
                $this->sfs->remove($path);

                return false;
            });

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $assetDir]),
            $fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::once())->method('addDependencies')->with($this->package, []);
        $this->manager->expects(self::once())->method('run')->willReturn(0);

        $solver->solve($this->composer, $this->io);

        self::assertFileExists($assetDir . '/.foxy-managed');
    }

    public function testSolveAcceptsEmptyUnmanagedAssetDirectory(): void
    {
        $this->addInstalledPackages();
        $assetDir = $this->cwd . '/empty-assets';
        $this->sfs->mkdir($assetDir);

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $assetDir]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::once())->method('addDependencies')->with($this->package, []);
        $this->manager->expects(self::once())->method('run')->willReturn(0);
        $this->composerFallback->expects(self::never())->method('restore');

        $solver->solve($this->composer, $this->io);

        self::assertFileExists($assetDir . '/.foxy-managed');
    }

    public function testSolveAcceptsOwnedAssetDirectoryOnSubsequentRuns(): void
    {
        $this->addInstalledPackages();
        $assetDir = $this->cwd . '/owned-assets';
        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $assetDir]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::exactly(2))->method('addDependencies');
        $this->manager->expects(self::exactly(2))->method('run')->willReturn(0);
        $this->composerFallback->expects(self::never())->method('restore');

        $solver->solve($this->composer, $this->io);
        $solver->solve($this->composer, $this->io);

        self::assertFileExists($assetDir . '/.foxy-managed');
    }

    public function testSolveAllowsDirectoryWhoseNamePrefixesConfiguredVendorDirectory(): void
    {
        $this->addInstalledPackages();
        $assetDir = $this->cwd . '-ven';
        $this->vendorDir = $this->cwd . '-vendor';

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $assetDir]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::once())->method('addDependencies')->with($this->package, []);
        $this->manager->expects(self::once())->method('run')->willReturn(0);
        $this->composerFallback->expects(self::never())->method('restore');

        $solver->solve($this->composer, $this->io);

        self::assertFileExists($assetDir . '/.foxy-managed');
    }

    public function testSolveDispatchesLifecycleEvents(): void
    {
        $this->addInstalledPackages();

        $assetDir = $this->cwd . '/composer-asset-dir';
        $canonicalAssetDir = $this->getCanonicalPath('/composer-asset-dir');
        $observedEvents = [];
        $this->dispatcher = $this->createMock(EventDispatcher::class);
        $this->dispatcher
            ->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(
                static function (string $name, object $event) use (&$observedEvents): int {
                    if ($event instanceof GetAssetsEvent) {
                        $observedEvents[] = [$name, $event->getAssetDir(), $event->getPackages()];
                        $event->addAsset('@injected/package', 'file:injected/package');

                        return 0;
                    }

                    if ($event instanceof PostSolveEvent) {
                        $observedEvents[] = [
                            $name,
                            $event->getAssetDir(),
                            $event->getPackages(),
                            $event->getRunResult(),
                        ];

                        return 0;
                    }

                    self::assertInstanceOf(PreSolveEvent::class, $event);
                    $observedEvents[] = [$name, $event->getAssetDir(), $event->getPackages()];

                    return 0;
                },
            );

        $this->manager
            ->expects(self::once())
            ->method('addDependencies')
            ->with($this->package, ['@injected/package' => 'file:injected/package']);
        $this->manager->expects(self::once())->method('run')->willReturn(0);
        $this->composerFallback->expects(self::never())->method('restore');

        $this->solver->solve($this->composer, $this->io);

        self::assertSame(
            [
                [FoxyEvents::PRE_SOLVE, $canonicalAssetDir, []],
                [FoxyEvents::GET_ASSETS, $canonicalAssetDir, []],
                [FoxyEvents::POST_SOLVE, $canonicalAssetDir, [], 0],
            ],
            $observedEvents,
        );
    }

    public function testSolveIgnoresPackageWithoutAssetActivation(): void
    {
        $package = $this->createMock(PackageInterface::class);
        $package->method('getName')->willReturn('vendor/package');
        $package->method('getExtra')->willReturn([]);
        $package->method('getRequires')->willReturn([]);
        $package->method('getDevRequires')->willReturn([]);

        $this->addInstalledPackages([$package]);

        $this->im->expects(self::never())->method('getInstallPath');
        $this->manager->expects(self::once())->method('addDependencies')->with($this->package, []);
        $this->manager->expects(self::once())->method('run')->willReturn(0);

        $this->solver->solve($this->composer, $this->io);
    }

    public function testSolvePreservesFailureWhenComposerFallbackThrows(): void
    {
        $this->addInstalledPackages();
        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $this->cwd . '/failed-fallback-assets']),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::once())->method('addDependencies');
        $this->manager->expects(self::once())->method('run')->willThrowException(new \RuntimeException('Manager failed.'));
        $this->composerFallback
            ->expects(self::once())
            ->method('restore')
            ->willThrowException(new \RuntimeException('Composer fallback failed.'));

        try {
            $solver->solve($this->composer, $this->io);
            self::fail('Expected fallback restoration to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Composer fallback restoration failed', $exception->getMessage());
            self::assertSame(0, $exception->getCode());
            self::assertSame('Manager failed.', $exception->getPrevious()?->getMessage());
        }
    }

    public function testSolveRejectsAssetDirectoryThatRemainsAfterSuccessfulRemoval(): void
    {
        $this->addInstalledPackages();
        $assetDir = $this->cwd . '/reported-removed-assets';
        $this->sfs->mkdir($assetDir);
        file_put_contents($assetDir . '/.foxy-managed', 'marker');

        $fs = $this->getMockBuilder(Filesystem::class)->onlyMethods(['remove'])->getMock();
        $fs
            ->expects(self::once())
            ->method('remove')
            ->with($this->getCanonicalPath('/reported-removed-assets'))
            ->willReturn(true);

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $assetDir]),
            $fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->manager->expects(self::never())->method('run');
        $this->composerFallback->expects(self::once())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to reset Composer asset directory');

        $solver->solve($this->composer, $this->io);
    }

    public function testSolveRejectsDirectoryContainingConfiguredVendorDirectory(): void
    {
        $this->addInstalledPackages();
        $assetDir = $this->cwd . '-vendor';
        $this->vendorDir = $assetDir . '/nested';
        $this->sfs->mkdir($this->vendorDir);

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $assetDir]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->composerFallback->expects(self::once())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overlaps a protected project path');

        $solver->solve($this->composer, $this->io);
    }

    public function testSolveRejectsFilesystemRootAsAssetDirectory(): void
    {
        $root = $this->getFilesystemRoot();
        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $root]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->composerFallback->expects(self::once())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not be a filesystem root');

        $solver->solve($this->composer, $this->io);
    }

    public function testSolveRejectsNonStringAssetDirectory(): void
    {
        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => []]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->composerFallback->expects(self::once())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Composer asset directory must be a string.');

        $solver->solve($this->composer, $this->io);
    }

    public function testSolveRejectsProjectRootAsAssetDirectory(): void
    {
        $this->vendorDir = $this->cwd . '-vendor';
        $this->sfs->mkdir($this->vendorDir);

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $this->cwd]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->composerFallback->expects(self::once())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overlaps a protected project path');

        $solver->solve($this->composer, $this->io);
    }

    public function testSolveRejectsRegularFileAsAssetDirectory(): void
    {
        $this->addInstalledPackages();
        $assetDir = $this->cwd . '/asset-file';
        file_put_contents($assetDir, 'not a directory');

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $assetDir]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->composerFallback->expects(self::once())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a directory');

        $solver->solve($this->composer, $this->io);
    }

    public function testSolveRejectsRelativeSymbolicLinkAssetDirectory(): void
    {
        $this->addInstalledPackages();
        $targetDir = $this->cwd . '/relative-symlink-target';
        $linkName = 'relative-symlink-assets';
        $linkDir = $this->cwd . '/' . $linkName;
        $this->sfs->mkdir($targetDir);

        if (!@symlink($targetDir, $linkDir)) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $linkName]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->composerFallback->expects(self::once())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not be a symbolic link');

        $solver->solve($this->composer, $this->io);
    }

    public function testSolveRejectsSymbolicLinkAssetDirectory(): void
    {
        $this->addInstalledPackages();
        $targetDir = $this->cwd . '/symlink-target';
        $linkDir = $this->cwd . '/symlink-assets';
        $this->sfs->mkdir($targetDir);
        file_put_contents($targetDir . '/.foxy-managed', 'marker');

        if (!@symlink($targetDir, $linkDir)) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $linkDir]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->composerFallback->expects(self::once())->method('restore');

        try {
            $solver->solve($this->composer, $this->io);
            self::fail('Expected a symbolic link exception.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('must not be a symbolic link', $exception->getMessage());
            self::assertFileExists($targetDir . '/.foxy-managed');
        }
    }

    public function testSolveRejectsUnmanagedNonEmptyAssetDirectory(): void
    {
        $this->addInstalledPackages();
        $assetDir = $this->cwd . '/custom-assets';
        $this->sfs->mkdir($assetDir);
        file_put_contents($assetDir . '/keep.txt', 'user data');

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $assetDir]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->composerFallback->expects(self::once())->method('restore');

        try {
            $solver->solve($this->composer, $this->io);
            self::fail('Expected an unmanaged directory exception.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('is not marked as managed by Foxy', $exception->getMessage());
            self::assertSame('user data', file_get_contents($assetDir . '/keep.txt'));
        }
    }

    public function testSolveRejectsUnresolvableVendorSymbolicLink(): void
    {
        $this->addInstalledPackages();
        $this->vendorDir = $this->cwd . '/broken-vendor';

        if (!@symlink($this->cwd . '/missing-vendor', $this->vendorDir)) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $this->cwd . '/safe-assets']),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->composerFallback->expects(self::once())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve path');

        $solver->solve($this->composer, $this->io);
    }

    public function testSolveRejectsVendorRootAsAssetDirectory(): void
    {
        $this->addInstalledPackages();
        $this->vendorDir = $this->cwd . '-vendor';
        $this->sfs->mkdir($this->vendorDir);

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $this->vendorDir]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->composerFallback->expects(self::once())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overlaps a protected project path');

        $solver->solve($this->composer, $this->io);
    }

    public function testSolveRejectsWhitespaceOnlyAssetDirectory(): void
    {
        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => " \t\n"]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->composerFallback->expects(self::once())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not be empty');

        $solver->solve($this->composer, $this->io);
    }

    public function testSolveRestoresComposerWhenManagerThrows(): void
    {
        $this->addInstalledPackages();
        $failure = new \RuntimeException('Manager process failed.');
        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $this->cwd . '/throwing-assets']),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::once())->method('addDependencies');
        $this->manager->expects(self::once())->method('run')->willThrowException($failure);
        $this->composerFallback->expects(self::once())->method('restore');

        try {
            $solver->solve($this->composer, $this->io);
            self::fail('Expected the manager failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testSolveThrowsForNonZeroResultWithoutComposerFallback(): void
    {
        $this->addInstalledPackages();
        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $this->cwd . '/no-fallback-assets']),
            $this->fs,
        );

        $this->manager->expects(self::once())->method('addDependencies');
        $this->manager->expects(self::once())->method('run')->willReturn(-1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error code -1');

        $solver->solve($this->composer, $this->io);
    }

    public function testSolveUsesConfiguredVendorDirectoryForDefaultAssetDirectory(): void
    {
        $this->addInstalledPackages();
        $this->vendorDir = $this->cwd . '-vendor';

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::once())->method('addDependencies')->with($this->package, []);
        $this->manager->expects(self::once())->method('run')->willReturn(0);
        $this->composerFallback->expects(self::never())->method('restore');

        $solver->solve($this->composer, $this->io);

        self::assertFileExists($this->vendorDir . '/php-forge/composer-asset/.foxy-managed');
    }

    public function testSolveUsesProjectVendorDirectoryWhenComposerVendorDirectoryIsEmpty(): void
    {
        $this->addInstalledPackages();
        $this->vendorDir = '';

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::once())->method('addDependencies')->with($this->package, []);
        $this->manager->expects(self::once())->method('run')->willReturn(0);
        $this->composerFallback->expects(self::never())->method('restore');

        $solver->solve($this->composer, $this->io);

        self::assertFileExists($this->cwd . '/vendor/php-forge/composer-asset/.foxy-managed');
    }

    /**
     * @throws Exception
     */
    public function testSolveWithDisableOption(): void
    {
        $config = new Config(['enabled' => false]);
        $solver = new Solver($this->manager, $config, $this->fs);

        $this->manager->expects(self::never())->method('run');

        $solver->solve($this->composer, $this->io);
    }

    public function testValidateAssetDirectoryThrowsWhenProjectDirectoryIsUnavailable(): void
    {
        MockerState::addCondition('Foxy\\Solver', 'getcwd', [], false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to get the current working directory.');

        $this->invokeSolverMethod('validateAssetDirectory', $this->cwd . '/assets', $this->vendorDir);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_solver_test_', true);
        $this->config = new Config(['enabled' => true, 'composer-asset-dir' => $this->cwd . '/composer-asset-dir']);
        $this->composer = $this->createMock(Composer::class);
        $this->composerConfig = $this->createMock(\Composer\Config::class);
        $this->io = $this->createMock(IOInterface::class);
        $this->dispatcher = new EventDispatcher($this->composer, $this->io);
        $this->fs = new Filesystem();
        $this->im = $this->createMock(InstallationManager::class);
        $this->sfs = new \Symfony\Component\Filesystem\Filesystem();
        $this->package = $this->createMock(RootPackageInterface::class);
        $this->manager = $this->createMock(AssetManagerInterface::class);
        $this->composerFallback = $this->createMock(FallbackInterface::class);
        $this->sfs->mkdir($this->cwd);
        $this->vendorDir = $this->cwd . '/vendor';
        $this->composerConfig
            ->method('get')
            ->willReturnCallback(
                fn($key, $flags = 0): string|null => match ($key) {
                    'vendor-dir' => $this->vendorDir,
                    'bin-dir' => $this->cwd . '/vendor/bin',
                    default => null,
                },
            );

        chdir($this->cwd);

        $this->localRepo = $this->createMock(InstalledArrayRepository::class);

        $rm = new RepositoryManager(
            $this->io,
            $this->composerConfig,
            new HttpDownloader($this->io, $this->composerConfig),
        );

        $rm->setLocalRepository($this->localRepo);

        $this->composer->expects(self::any())->method('getRepositoryManager')->willReturn($rm);
        $this->composer->expects(self::any())->method('getInstallationManager')->willReturn($this->im);
        $this->composer->expects(self::any())->method('getPackage')->willReturn($this->package);
        $this->composer->expects(self::any())->method('getConfig')->willReturn($this->composerConfig);
        $this->composer
            ->expects(self::any())
            ->method('getEventDispatcher')
            ->willReturnCallback(
                fn(): EventDispatcher => $this->dispatcher ?? throw new LogicException('Missing event dispatcher.'),
            );

        $this->solver = new Solver($this->manager, $this->config, $this->fs, $this->composerFallback);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);
        $this->sfs->remove([$this->cwd, $this->cwd . '-vendor', $this->cwd . '-ven']);
        $this->config = null;
        $this->composer = null;
        $this->composerConfig = null;
        $this->dispatcher = null;
        $this->localRepo = null;
        $this->io = null;
        $this->fs = null;
        $this->im = null;
        $this->sfs = null;
        $this->package = null;
        $this->manager = null;
        $this->composerFallback = null;
        $this->solver = null;
        $this->oldCwd = null;
        $this->cwd = null;
    }

    /**
     * Add the installed packages in local repository.
     *
     * @param PackageInterface[] $packages The installed packages
     */
    private function addInstalledPackages(array $packages = []): void
    {
        $this->localRepo->expects(self::any())->method('getCanonicalPackages')->willReturn($packages);
    }

    private function getCanonicalPath(string $suffix = ''): string
    {
        $cwd = realpath((string) $this->cwd);

        if (false === $cwd) {
            self::fail('Unable to resolve the test working directory.');
        }

        return $this->fs->normalizePath(rtrim($cwd, '/\\') . $suffix);
    }

    private function getFilesystemRoot(): string
    {
        return '\\' === DIRECTORY_SEPARATOR
            ? substr($this->getCanonicalPath(), 0, 3)
            : DIRECTORY_SEPARATOR;
    }

    private function invokeSolverMethod(string $method, mixed ...$arguments): mixed
    {
        return $this->invokeSolverMethodOn($this->solver, $method, ...$arguments);
    }

    private function invokeSolverMethodOn(SolverInterface $solver, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionClass($solver))->getMethod($method)->invoke($solver, ...$arguments);
    }
}
