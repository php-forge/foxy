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

namespace Foxy\Tests\Solver;

use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Foxy\Asset\AssetManagerInterface;
use Foxy\Config\Config;
use Foxy\Fallback\FallbackInterface;
use Foxy\Solver\Solver;
use Foxy\Solver\SolverInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for solver.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
class SolverTest extends \PHPUnit\Framework\TestCase
{
    private Config|null $config = null;
    private Composer|MockObject|null $composer = null;
    private \Composer\Config|MockObject|null $composerConfig = null;
    private MockObject|WritableRepositoryInterface|null $localRepo = null;
    private IOInterface|MockObject|null $io = null;
    private Filesystem|MockObject|null $fs = null;
    private InstallationManager|MockObject|null $im = null;
    private \Symfony\Component\Filesystem\Filesystem|MockObject|null $sfs = null;
    private MockObject|RootPackageInterface|null $package = null;
    private AssetManagerInterface|MockObject|null $manager = null;
    private FallbackInterface|MockObject|null $composerFallback = null;
    private string|null $oldCwd = '';
    private string|null $cwd = '';
    private SolverInterface|null $solver = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . uniqid('foxy_solver_test_', true);
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

        \chdir($this->cwd);

        $this->localRepo = $this->createMock(InstalledArrayRepository::class);

        if (\class_exists(HttpDownloader::class)) {
            $rm = new RepositoryManager($this->io, $this->composerConfig, new HttpDownloader($this->io, $this->composerConfig));
            $rm->setLocalRepository($this->localRepo);
        } else {
            $rm = new RepositoryManager($this->io, $this->composerConfig);
            $rm->setLocalRepository($this->localRepo);
        }

        $this->composer->expects($this->any())->method('getRepositoryManager')->willReturn($rm);
        $this->composer->expects($this->any())->method('getInstallationManager')->willReturn($this->im);
        $this->composer->expects($this->any())->method('getPackage')->willReturn($this->package);
        $this->composer->expects($this->any())->method('getConfig')->willReturn($this->composerConfig);
        $this->composer
            ->expects($this->any())
            ->method('getEventDispatcher')
            ->willReturn(new EventDispatcher($this->composer, $this->io));

        $sfs = $this->sfs;

        $this->fs
            ->expects($this->any())
            ->method('findShortestPath')
            ->willReturnCallback(fn ($from, $to) => rtrim($sfs->makePathRelative($to, $from), '/'));

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

    public function testSetUpdatable(): void
    {
        $this->manager->expects($this->once())->method('setUpdatable')->with(false);
        $this->solver->setUpdatable(false);
    }

    public function testSolveWithDisableOption(): void
    {
        $config = new Config(['enabled' => false]);
        $solver = new Solver($this->manager, $config, $this->fs);

        $this->manager->expects($this->never())->method('run');

        $solver->solve($this->composer, $this->io);
    }

    public static function getSolveData(): array
    {
        return [[0], [1]];
    }

    /**
     * @dataProvider getSolveData
     *
     * @param int $resRunManager The result value of the run command of asset manager
     */
    public function testSolve(int $resRunManager): void
    {
        /** @var MockObject|PackageInterface $requirePackage */
        $requirePackage = $this->createMock(PackageInterface::class);

        $requirePackage->expects($this->any())->method('getPrettyVersion')->willReturn('1.0.0');
        $requirePackage->expects($this->any())->method('getName')->willReturn('foo/bar');
        $requirePackage
            ->expects($this->any())
            ->method('getRequires')
            ->willReturn([new Link('root/package', 'php-forge/foxy', new Constraint('=', '1.0.0'))]);
        $requirePackage->expects($this->any())->method('getDevRequires')->willReturn([]);

        $this->addInstalledPackages([$requirePackage]);

        $requirePackagePath = $this->cwd . '/vendor/foo/bar';

        $this->im->expects($this->once())->method('getInstallPath')->willReturn($requirePackagePath);
        $this->manager->expects($this->exactly(2))->method('getPackageName')->willReturn('package.json');
        $this->manager->expects($this->once())->method('addDependencies');
        $this->manager->expects($this->once())->method('run')->willReturn($resRunManager);

        if (0 === $resRunManager) {
            $this->composerFallback->expects($this->never())->method('restore');
        } else {
            $this->composerFallback->expects($this->once())->method('restore');

            $this->expectException('RuntimeException');
            $this->expectExceptionMessage('The asset manager ended with an error');
        }

        $requirePackageFilename = $requirePackagePath . \DIRECTORY_SEPARATOR . $this->manager->getPackageName();

        $this->sfs->mkdir(\dirname($requirePackageFilename));

        \file_put_contents($requirePackageFilename, '{}');

        $this->solver->solve($this->composer, $this->io);
    }

    /**
     * Add the installed packages in local repository.
     *
     * @param PackageInterface[] $packages The installed packages
     */
    private function addInstalledPackages(array $packages = []): void
    {
        $this->localRepo->expects($this->any())->method('getCanonicalPackages')->willReturn($packages);
    }
}
