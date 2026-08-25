<?php

declare(strict_types=1);

namespace Foxy\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Installer\{InstallationManager, PackageEvent};
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\{Package, RootPackageInterface};
use Composer\Repository\RepositoryManager;
use Composer\Script\{Event, ScriptEvents};
use Foxy\Asset\{AbstractAssetManager, AssetManagerInterface};
use Foxy\Config\Config as FoxyConfig;
use Foxy\Exception\RuntimeException;
use Foxy\Fallback\AssetFallback;
use Foxy\Foxy;
use Foxy\Solver\SolverInterface;
use Foxy\Tests\Fixtures\Asset\StubAssetManager;
use Foxy\Util\ComposerUtil;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Seld\JsonLint\ParsingException;

use function getcwd;

final class FoxyTest extends TestCase
{
    private Composer|MockObject $composer;
    private IOInterface $io;
    private RootPackageInterface|MockObject $package;

    public static function getRunAssetManagerData(): array
    {
        return [
            'boolean true' => [true, true],
            'integer one' => [1, true],
            'string one' => ['1', true],
            'boolean false' => [false, false],
            'integer two' => [2, false],
            'string two' => ['2', false],
        ];
    }

    public static function getSolveAssetsData(): array
    {
        return [['solve_event_install', false], ['solve_event_update', true]];
    }

    /**
     * @throws ParsingException
     */
    public function testActivate(): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'npm', 'run-asset-manager' => false]]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);
        $foxy->init();

        $assetFallback = $this->getFoxyProperty($foxy, 'assetFallback');
        $assetManager = $this->getFoxyProperty($foxy, 'assetManager');
        $composerFallback = $this->getFoxyProperty($foxy, 'composerFallback');

        self::assertTrue($this->getFoxyProperty($foxy, 'initialized'));
        self::assertTrue($this->getObjectProperty($assetFallback, 'snapshotSaved'));
        self::assertTrue($this->getObjectProperty($composerFallback, 'snapshotSaved'));
        self::assertSame(
            $assetFallback,
            (new ReflectionClass(AbstractAssetManager::class))->getProperty('fallback')->getValue($assetManager),
        );
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
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'npm', 'run-asset-manager' => false]]);

        $package = $this->createMock(Package::class);
        $package->expects(self::once())->method('getName')->willReturn('php-forge/foxy');
        $operation = $this->createMock(InstallOperation::class);
        $operation->expects(self::once())->method('getPackage')->willReturn($package);
        $event = $this->createMock(PackageEvent::class);
        $event->expects(self::once())->method('getOperation')->willReturn($operation);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);
        $foxy->initOnInstall($event);

        self::assertTrue($this->getFoxyProperty($foxy, 'initialized'));
    }

    /**
     * @throws ParsingException
     */
    public function testActivateOnInstallIgnoresDifferentPackage(): void
    {
        $package = $this->createMock(Package::class);
        $package->expects(self::once())->method('getName')->willReturn('vendor/package');
        $operation = $this->createMock(InstallOperation::class);
        $operation->expects(self::once())->method('getPackage')->willReturn($package);
        $event = $this->createMock(PackageEvent::class);
        $event->expects(self::once())->method('getOperation')->willReturn($operation);

        $foxy = new Foxy();
        $foxy->activate($this->composer, $this->io);
        $foxy->initOnInstall($event);

        self::assertFalse($this->getFoxyProperty($foxy, 'initialized'));
    }

    /**
     * @throws ParsingException
     */
    public function testActivateOnInstallIgnoresNonInstallOperation(): void
    {
        $operation = $this->createMock(OperationInterface::class);
        $event = $this->createMock(PackageEvent::class);
        $event->expects(self::once())->method('getOperation')->willReturn($operation);

        $foxy = new Foxy();
        $foxy->activate($this->composer, $this->io);
        $foxy->initOnInstall($event);

        self::assertFalse($this->getFoxyProperty($foxy, 'initialized'));
    }

    public function testActivateRejectsUnsupportedComposerVersion(): void
    {
        $foxy = new Foxy();
        (new ReflectionClass($foxy))->getProperty('composerVersion')->setValue($foxy, '2.9.0');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Foxy requires the Composer\'s minimum version "^2.10.2"');

        $foxy->activate($this->composer, $this->io);
    }

    /**
     * @throws ParsingException
     */
    public function testActivateSkipsManagerDiscoveryWhenDisabled(): void
    {
        $this->package
            ->expects(self::any())
            ->method('getConfig')
            ->willReturn(['foxy' => ['enabled' => false, 'manager' => 'invalid_manager']]);

        $foxy = new Foxy();
        $foxy->activate($this->composer, $this->io);
        $foxy->init();

        $assetManager = (new ReflectionClass($foxy))->getProperty('assetManager');

        self::assertFalse($assetManager->isInitialized($foxy));
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
        $originalAssetManagers = $assetManagersProperty->getValue();
        $assetManagersProperty->setValue(null, [StubAssetManager::class]);

        try {
            $foxy = new Foxy();

            $foxy->activate($this->composer, $this->io);
            $assetFallbackProperty = $foxyReflection->getProperty('assetFallback');
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

    public function testConfigurationFlagsRemainCompatibleDuringPluginSelfUpdate(): void
    {
        $config = $this
            ->getMockBuilder(FoxyConfig::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'isEnabled'])
            ->getMock();

        $config
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnCallback(
                static fn(string $key): bool|int => 'enabled' === $key ? 1 : false,
            );
        $config->expects(self::never())->method('isEnabled');

        $foxy = new Foxy();
        $reflection = new ReflectionClass($foxy);
        $reflection->getProperty('config')->setValue($foxy, $config);
        $isEnabled = $reflection->getMethod('isEnabled');

        self::assertTrue($isEnabled->invoke($foxy));
        self::assertFalse($isEnabled->invoke($foxy, 'run-asset-manager'));
    }

    public function testDeactivate(): void
    {
        $foxy = new Foxy();

        $foxy->deactivate($this->composer, $this->io);

        $this->expectNotToPerformAssertions();
    }

    public function testGetSubscribedEvents(): void
    {
        self::assertSame(
            [
                ComposerUtil::getInitEventName() => [['init', 100]],
                PackageEvents::POST_PACKAGE_INSTALL => [['initOnInstall', 100]],
                ScriptEvents::POST_INSTALL_CMD => [['solveAssets', 100]],
                ScriptEvents::POST_UPDATE_CMD => [['solveAssets', 100]],
            ],
            Foxy::getSubscribedEvents(),
        );
    }

    /**
     * @throws ParsingException
     */
    #[DataProvider('getRunAssetManagerData')]
    public function testInitHonorsRunAssetManagerValues(mixed $value, bool $expectedValidation): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'npm', 'run-asset-manager' => $value]]);

        $foxy = new Foxy();
        $foxy->activate($this->composer, $this->io);

        $assetManager = $this->createMock(AssetManagerInterface::class);
        $assetManager
            ->expects($expectedValidation ? self::once() : self::never())
            ->method('validate');

        (new ReflectionClass($foxy))->getProperty('assetManager')->setValue($foxy, $assetManager);

        $foxy->init();
    }

    /**
     * @throws ParsingException
     */
    public function testIntegerOneEnablesPlugin(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The asset manager "invalid_manager" doesn\'t exist');

        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['enabled' => 1, 'manager' => 'invalid_manager']]);

        (new Foxy())->activate($this->composer, $this->io);
    }

    #[DataProvider('getSolveAssetsData')]
    public function testSolveAssets(string $eventName, bool $expectedUpdatable): void
    {
        $event = new Event($eventName, $this->composer, $this->io);

        $solver = $this->createMock(SolverInterface::class);

        $solver->expects(self::once())->method('setUpdatable')->with($expectedUpdatable);
        $solver->expects(self::once())->method('solve')->with($this->composer, $this->io);

        $foxy = new Foxy();

        $foxy->setSolver($solver);
        $foxy->solveAssets($event);
    }

    /**
     * @throws ParsingException
     */
    public function testSolveAssetsDoesNothingWhenDisabled(): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['enabled' => false, 'manager' => 'invalid_manager']]);

        $solver = $this->createMock(SolverInterface::class);
        $solver->expects(self::never())->method('setUpdatable');
        $solver->expects(self::never())->method('solve');

        $foxy = new Foxy();
        $foxy->activate($this->composer, $this->io);
        $foxy->setSolver($solver);
        $foxy->solveAssets(new Event('solve_event_install', $this->composer, $this->io));
    }

    public function testUninstall(): void
    {
        $foxy = new Foxy();

        $foxy->uninstall($this->composer, $this->io);

        $this->expectNotToPerformAssertions();
    }

    protected function setUp(): void
    {
        $this->composer = $this->createMock(Composer::class);
        $composerConfig = $this->createMock(Config::class);
        $composerConfig
            ->method('get')
            ->willReturnCallback(
                static fn($key, $flags = 0): string|null => 'vendor-dir' === $key ? getcwd() . '/vendor' : null,
            );
        $this->io = $this->createMock(IOInterface::class);
        $this->package = $this->createMock(RootPackageInterface::class);

        $this->composer
            ->expects(self::any())
            ->method('getPackage')
            ->willReturn($this->package);

        $this->composer
            ->expects(self::any())
            ->method('getConfig')
            ->willReturn($composerConfig);

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

    private function getFoxyProperty(Foxy $foxy, string $name): mixed
    {
        return (new ReflectionClass($foxy))->getProperty($name)->getValue($foxy);
    }

    private function getObjectProperty(object $object, string $name): mixed
    {
        return (new ReflectionClass($object))->getProperty($name)->getValue($object);
    }
}
