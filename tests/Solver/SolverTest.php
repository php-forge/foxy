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
use Foxy\Exception\RuntimeException;
use Foxy\Fallback\FallbackInterface;
use Foxy\Solver\{Solver, SolverInterface};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function chdir;
use function dirname;
use function file_put_contents;

use const DIRECTORY_SEPARATOR;

class SolverTest extends TestCase
{
    private Composer|MockObject|null $composer = null;
    private \Composer\Config|MockObject|null $composerConfig = null;
    private FallbackInterface|MockObject|null $composerFallback = null;
    private Config|null $config = null;
    private string|null $cwd = '';
    private Filesystem|null $fs = null;
    private InstallationManager|MockObject|null $im = null;
    private IOInterface|MockObject|null $io = null;
    private MockObject|WritableRepositoryInterface|null $localRepo = null;
    private AssetManagerInterface|MockObject|null $manager = null;
    private string|null $oldCwd = '';
    private MockObject|RootPackageInterface|null $package = null;
    private \Symfony\Component\Filesystem\Filesystem|MockObject|null $sfs = null;
    private SolverInterface|null $solver = null;

    public static function getSolveData(): array
    {
        return [[0], [1]];
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
        $this->manager->expects(self::once())->method('addDependencies');
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
            self::assertSame('Manager failed.', $exception->getPrevious()?->getMessage());
        }
    }

    public function testSolveRejectsProjectRootAsAssetDirectory(): void
    {
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

    public function testSolveRejectsVendorRootAsAssetDirectory(): void
    {
        $this->addInstalledPackages();
        $vendorDir = $this->cwd . '/vendor';
        $this->sfs->mkdir($vendorDir);

        $solver = new Solver(
            $this->manager,
            new Config(['enabled' => true, 'composer-asset-dir' => $vendorDir]),
            $this->fs,
            $this->composerFallback,
        );

        $this->manager->expects(self::never())->method('addDependencies');
        $this->composerFallback->expects(self::once())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overlaps a protected project path');

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_solver_test_', true);
        $this->config = new Config(['enabled' => true, 'composer-asset-dir' => $this->cwd . '/composer-asset-dir']);
        $this->composer = $this->createMock(Composer::class);
        $this->composerConfig = $this->createMock(\Composer\Config::class);
        $this->io = $this->createMock(IOInterface::class);
        $this->fs = new Filesystem();
        $this->im = $this->createMock(InstallationManager::class);
        $this->sfs = new \Symfony\Component\Filesystem\Filesystem();
        $this->package = $this->createMock(RootPackageInterface::class);
        $this->manager = $this->createMock(AssetManagerInterface::class);
        $this->composerFallback = $this->createMock(FallbackInterface::class);
        $this->sfs->mkdir($this->cwd);
        $vendorDir = $this->cwd . '/vendor';
        $this->composerConfig
            ->method('get')
            ->willReturnCallback(static fn($key, $flags = 0): string|null => 'vendor-dir' === $key ? $vendorDir : null);

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
            ->willReturn(new EventDispatcher($this->composer, $this->io));

        $this->solver = new Solver($this->manager, $this->config, $this->fs, $this->composerFallback);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);
        $this->sfs->remove($this->cwd);
        $this->config = null;
        $this->composer = null;
        $this->composerConfig = null;
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
}
