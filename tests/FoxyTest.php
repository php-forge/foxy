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

namespace Foxy\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Installer\InstallationManager;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Foxy\Foxy;
use Foxy\Solver\SolverInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for foxy.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class FoxyTest extends \PHPUnit\Framework\TestCase
{
    private Composer|MockObject $composer;
    private Config $composerConfig;
    private IOInterface $io;
    private RootPackageInterface|MockObject $package;

    protected function setUp(): void
    {
        $this->composer = $this->createMock(Composer::class);
        $this->composerConfig = $this->createMock(Config::class);
        $this->io = $this->createMock(IOInterface::class);
        $this->package = $this->createMock(RootPackageInterface::class);

        $this->composer
            ->expects($this->any())
            ->method('getPackage')
            ->willReturn($this->package);

        $this->composer
            ->expects($this->any())
            ->method('getConfig')
            ->willReturn($this->composerConfig);

        $rm = $this->createMock(RepositoryManager::class);

        $this->composer
            ->expects($this->any())
            ->method('getRepositoryManager')
            ->willReturn($rm);

        $im = $this->createMock(InstallationManager::class);

        $this->composer
            ->expects($this->any())
            ->method('getInstallationManager')
            ->willReturn($im)
        ;
    }

    public function testGetSubscribedEvents(): void
    {
        $this->assertCount(4, Foxy::getSubscribedEvents());
    }

    public function testActivate(): void
    {
        $foxy = new Foxy();
        $foxy->activate($this->composer, $this->io);
        $foxy->init();

        $this->assertTrue(true);
    }

    public function testDeactivate(): void
    {
        $foxy = new Foxy();
        $foxy->deactivate($this->composer, $this->io);

        $this->assertTrue(true);
    }

    public function testUninstall(): void
    {
        $foxy = new Foxy();
        $foxy->uninstall($this->composer, $this->io);

        $this->assertTrue(true);
    }

    public function testActivateOnInstall(): void
    {
        $package = $this->createMock(Package::class);

        $package->expects($this->once())->method('getName')->willReturn('php-forge/foxy');

        $operation = $this->createMock(InstallOperation::class);

        $operation->expects($this->once())->method('getPackage')->willReturn($package);

        /** @var MockObject|PackageEvent $event */
        $event = $this->createMock(PackageEvent::class);

        $event->expects($this->once())->method('getOperation')->willReturn($operation);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);
        $foxy->initOnInstall($event);
    }

    public function testActivateWithInvalidManager(): void
    {
        $this->expectException(\Foxy\Exception\RuntimeException::class);
        $this->expectExceptionMessage('The asset manager "invalid_manager" doesn\'t exist');

        $this->package
            ->expects($this->any())
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'invalid_manager']]);

        $foxy = new Foxy();
        $foxy->activate($this->composer, $this->io);
    }

    public static function getSolveAssetsData(): array
    {
        return [['solve_event_install', false], ['solve_event_update', true]];
    }

    /**
     * @dataProvider getSolveAssetsData
     */
    public function testSolveAssets(string $eventName, bool $expectedUpdatable): void
    {
        $event = new Event($eventName, $this->composer, $this->io);

        /** @var MockObject|SolverInterface $solver */
        $solver = $this->createMock(SolverInterface::class);

        $solver->expects($this->once())->method('setUpdatable')->with($expectedUpdatable);
        $solver->expects($this->once())->method('solve')->with($this->composer, $this->io);

        $foxy = new Foxy();

        $foxy->setSolver($solver);
        $foxy->solveAssets($event);
    }
}
