<?php

declare(strict_types=1);

namespace Foxy\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Installer\{InstallationManager, PackageEvent};
use Composer\IO\IOInterface;
use Composer\Package\{Package, RootPackageInterface};
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Foxy\Exception\RuntimeException;
use Foxy\Fallback\AssetFallback;
use Foxy\Foxy;
use Foxy\Solver\SolverInterface;
use Foxy\Tests\Fixtures\Asset\StubAssetManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Seld\JsonLint\ParsingException;

use function getcwd;

use const PHP_VERSION_ID;

final class FoxyTest extends TestCase
{
    private Composer|MockObject $composer;
    private Config $composerConfig;
    private IOInterface $io;
    private RootPackageInterface|MockObject $package;

    public static function getSolveAssetsData(): array
    {
        return [['solve_event_install', false], ['solve_event_update', true]];
    }

    /**
     * @throws ParsingException
     */
    public function testActivate(): void
    {
        $foxy = new Foxy();
        $foxy->activate($this->composer, $this->io);
        $foxy->init();

        self::assertTrue(true);
    }

    /**
     * @throws ParsingException
     */
    public function testActivateBuildsAssetFallbackWithResolvedRootPackagePath(): void
    {
        $this->package
            ->expects(self::any())
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'npm', 'root-package-json-dir' => 'root-package']]);

        $foxy = new Foxy();
        $foxy->activate($this->composer, $this->io);

        $foxyReflection = new ReflectionClass($foxy);
        $assetFallbackProperty = $foxyReflection->getProperty('assetFallback');
        $assetFallback = $assetFallbackProperty->getValue($foxy);

        self::assertInstanceOf(AssetFallback::class, $assetFallback);

        $fallbackReflection = new ReflectionClass($assetFallback);

        $pathProperty = $fallbackReflection->getProperty('path');
        $expectedPath = rtrim((string) getcwd(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'root-package'
            . DIRECTORY_SEPARATOR
            . 'package.json';

        self::assertSame($expectedPath, $pathProperty->getValue($assetFallback));
    }

    /**
     * @throws ParsingException
     */
    public function testActivateOnInstall(): void
    {
        $package = $this->createMock(Package::class);

        $package->expects(self::once())->method('getName')->willReturn('php-forge/foxy');

        $operation = $this->createMock(InstallOperation::class);

        $operation->expects(self::once())->method('getPackage')->willReturn($package);

        /** @var MockObject|PackageEvent $event */
        $event = $this->createMock(PackageEvent::class);

        $event->expects(self::once())->method('getOperation')->willReturn($operation);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);
        $foxy->initOnInstall($event);
    }

    /**
     * @throws ParsingException|ReflectionException
     */
    public function testActivateUsesPackageNameForNonAbstractAssetManager(): void
    {
        $this->package
            ->expects(self::any())
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'stub']]);

        $foxyReflection = new ReflectionClass(Foxy::class);
        $assetManagersProperty = $foxyReflection->getProperty('assetManagers');

        if (PHP_VERSION_ID < 80500) {
        }

        $originalAssetManagers = $assetManagersProperty->getValue();
        $assetManagersProperty->setValue(null, [StubAssetManager::class]);

        try {
            $foxy = new Foxy();
            $foxy->activate($this->composer, $this->io);

            $assetFallbackProperty = $foxyReflection->getProperty('assetFallback');

            if (PHP_VERSION_ID < 80500) {
            }

            $assetFallback = $assetFallbackProperty->getValue($foxy);

            $fallbackReflection = new ReflectionClass($assetFallback);

            $pathProperty = $fallbackReflection->getProperty('path');

            self::assertSame('stub-package.json', $pathProperty->getValue($assetFallback));
        } finally {
            $assetManagersProperty->setValue(null, $originalAssetManagers);
        }
    }

    /**
     * @throws ParsingException
     */
    public function testActivateWithInvalidManager(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The asset manager "invalid_manager" doesn\'t exist');

        $this->package
            ->expects(self::any())
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'invalid_manager']]);

        $foxy = new Foxy();
        $foxy->activate($this->composer, $this->io);
    }

    public function testDeactivate(): void
    {
        $foxy = new Foxy();
        $foxy->deactivate($this->composer, $this->io);

        self::assertTrue(true);
    }

    public function testGetSubscribedEvents(): void
    {
        self::assertCount(4, Foxy::getSubscribedEvents());
    }

    /**
     * @dataProvider getSolveAssetsData
     */
    public function testSolveAssets(string $eventName, bool $expectedUpdatable): void
    {
        $event = new Event($eventName, $this->composer, $this->io);

        /** @var MockObject|SolverInterface $solver */
        $solver = $this->createMock(SolverInterface::class);

        $solver->expects(self::once())->method('setUpdatable')->with($expectedUpdatable);
        $solver->expects(self::once())->method('solve')->with($this->composer, $this->io);

        $foxy = new Foxy();

        $foxy->setSolver($solver);
        $foxy->solveAssets($event);
    }

    public function testUninstall(): void
    {
        $foxy = new Foxy();
        $foxy->uninstall($this->composer, $this->io);

        self::assertTrue(true);
    }

    protected function setUp(): void
    {
        $this->composer = $this->createMock(Composer::class);
        $this->composerConfig = $this->createMock(Config::class);
        $this->io = $this->createMock(IOInterface::class);
        $this->package = $this->createMock(RootPackageInterface::class);

        $this->composer
            ->expects(self::any())
            ->method('getPackage')
            ->willReturn($this->package);

        $this->composer
            ->expects(self::any())
            ->method('getConfig')
            ->willReturn($this->composerConfig);

        $rm = $this->createMock(RepositoryManager::class);

        $this->composer
            ->expects(self::any())
            ->method('getRepositoryManager')
            ->willReturn($rm);

        $im = $this->createMock(InstallationManager::class);

        $this->composer
            ->expects(self::any())
            ->method('getInstallationManager')
            ->willReturn($im)
        ;
    }
}
