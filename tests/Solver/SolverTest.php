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
use Foxy\Fallback\FallbackInterface;
use Foxy\Solver\{Solver, SolverInterface};
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
    private Filesystem|MockObject|null $fs = null;
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
     * @dataProvider getSolveData
     *
     * @param int $resRunManager The result value of the run command of asset manager
     *
     * @throws Exception
     */
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
            $this->expectExceptionMessage('The asset manager ended with an error');
        }

        $requirePackageFilename = $requirePackagePath . DIRECTORY_SEPARATOR . $this->manager->getPackageName();

        $this->sfs->mkdir(dirname($requirePackageFilename));

        file_put_contents($requirePackageFilename, '{}');

        $this->solver->solve($this->composer, $this->io);
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
        $this->fs = $this->createMock(Filesystem::class);
        $this->im = $this->createMock(InstallationManager::class);
        $this->sfs = new \Symfony\Component\Filesystem\Filesystem();
        $this->package = $this->createMock(RootPackageInterface::class);
        $this->manager = $this->createMock(AssetManagerInterface::class);
        $this->composerFallback = $this->createMock(FallbackInterface::class);
        $this->sfs->mkdir($this->cwd);

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

        $sfs = $this->sfs;

        $this->fs
            ->expects(self::any())
            ->method('findShortestPath')
            ->willReturnCallback(fn(string $from, string $to): string => rtrim($sfs->makePathRelative($to, $from), '/'));

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
