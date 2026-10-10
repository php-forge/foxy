<?php

declare(strict_types=1);

namespace Foxy\Tests;

use Composer\{Cache, Composer, Config};
use Composer\DependencyResolver\Operation\{InstallOperation, OperationInterface};
use Composer\Installer\{InstallationManager, InstallerEvents, PackageEvent, PackageEvents};
use Composer\IO\IOInterface;
use Composer\Package\{Package, RootPackageInterface};
use Composer\Repository\RepositoryManager;
use Composer\Script\{Event, ScriptEvents};
use Composer\Util\{Filesystem, HttpDownloader, Loop, ProcessExecutor};
use Foxy\Asset\{AssetManagerFinder, AssetManagerInterface, DenoManager, NativeManager, NpmManager};
use Foxy\Config\Config as FoxyConfig;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Fallback\AssetFallback;
use Foxy\Foxy;
use Foxy\Native\NpmRegistry;
use Foxy\Solver\SolverInterface;
use Foxy\Tests\Fixtures\Asset\StubAssetManager;
use Foxy\Tests\Provider\FoxyProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Seld\JsonLint\ParsingException;

use function getcwd;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;

/**
 * Unit tests for the {@see Foxy} Composer plugin lifecycle and event handling.
 *
 * {@see FoxyProvider} for test case data providers.
 */
final class FoxyTest extends TestCase
{
    private Composer|MockObject $composer;

    /**
     * @var array<string, bool|string|null> Composer configuration values that override the defaults of the mock.
     */
    private array $composerSettings = [];
    private IOInterface $io;
    private RootPackageInterface|MockObject $package;

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

        self::assertTrue(
            $this->getFoxyProperty($foxy, 'initialized'),
            'Initialization flag must be set.',
        );
        self::assertTrue(
            $this->getObjectProperty($assetFallback, 'snapshotSaved'),
            'Asset snapshot must be saved.',
        );
        self::assertTrue(
            $this->getObjectProperty($composerFallback, 'snapshotSaved'),
            'Composer snapshot must be saved.',
        );
        $solver = $this->invokeFoxyMethod($foxy, 'getSolver');

        self::assertSame(
            $solver,
            $this->invokeFoxyMethod($foxy, 'getSolver'),
            'Solver must be built once.',
        );
        self::assertSame(
            $assetFallback,
            $this->getObjectProperty($solver, 'assetFallback'),
            'Solver and plugin must share the asset fallback.',
        );
        self::assertSame(
            $assetManager,
            $this->getObjectProperty($solver, 'assetManager'),
            'Solver and plugin must share the asset manager.',
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

        $this->resolveAssetManager($foxy);

        $foxyReflection = new ReflectionClass($foxy);

        $assetFallbackProperty = $foxyReflection->getProperty('assetFallback');
        $assetFallback = $assetFallbackProperty->getValue($foxy);

        self::assertInstanceOf(
            AssetFallback::class,
            $assetFallback,
            'Fallback must use the asset implementation.',
        );

        $fallbackReflection = new ReflectionClass($assetFallback);

        $pathProperty = $fallbackReflection->getProperty('path');

        $expectedPath = rtrim((string) getcwd(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'root-package'
            . DIRECTORY_SEPARATOR
            . 'package.json';

        self::assertSame(
            $expectedPath,
            $pathProperty->getValue($assetFallback),
            'Fallback path must target the configured manifest.',
        );
    }

    /**
     * @throws ParsingException
     */
    public function testActivateDefaultsNativeInstallDirToNodeModules(): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'native', 'run-asset-manager' => false]]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        $config = $this->getFoxyProperty($foxy, 'config');

        self::assertInstanceOf(
            FoxyConfig::class,
            $config,
            'The plugin must hold its configuration.',
        );
        self::assertSame(
            'node_modules',
            $config->get('native-install-dir'),
            'The default must be `node_modules`.',
        );
    }

    /**
     * @throws ParsingException
     */
    public function testActivateDoesNotResolveManagerWhenEnabled(): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'invalid_manager']]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        $reflection = new ReflectionClass($foxy);

        self::assertFalse(
            $reflection->getProperty('assetManager')->isInitialized($foxy),
            'Manager must stay unresolved after activation.',
        );
        self::assertFalse(
            $reflection->getProperty('assetFallback')->isInitialized($foxy),
            'Asset fallback must not be built before the manager.',
        );
        self::assertFalse(
            $reflection->getProperty('solver')->isInitialized($foxy),
            'Solver must not be built before the manager.',
        );
    }

    /**
     * @throws ParsingException|ReflectionException
     */
    #[DataProviderExternal(FoxyProvider::class, 'cacheReadOnlyValues')]
    public function testActivateHonorsCacheReadOnly(bool|null $value, bool $expected): void
    {
        $this->composerSettings['cache-read-only'] = $value;

        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'native', 'run-asset-manager' => false]]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        $cache = $this->getObjectProperty($this->getNativeRegistry($foxy), 'cache');

        self::assertInstanceOf(
            Cache::class,
            $cache,
            'The registry must hold a Composer cache.',
        );
        self::assertSame(
            $expected,
            $cache->isReadOnly(),
            'The read-only flag must follow Composer.',
        );
        self::assertSame(
            sys_get_temp_dir() . '/foxy-test-cache/foxy/',
            $cache->getRoot(),
            'Tarballs must be cached under the Composer files cache.',
        );
    }

    /**
     * @throws ParsingException
     */
    public function testActivateKeepsNpmFirstForAutomaticDiscovery(): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(
                [
                    'foxy' => [
                        'root-package-json-dir' => __DIR__ . '/Fixtures/package/global',
                        'run-asset-manager' => false,
                    ],
                ],
            );

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        $this->resolveAssetManager($foxy);

        self::assertInstanceOf(
            NpmManager::class,
            $this->getFoxyProperty($foxy, 'assetManager'),
            'Npm manager must be selected first.',
        );
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

        $package
            ->expects(self::once())
            ->method('getName')
            ->willReturn('php-forge/foxy');

        $operation = $this->createMock(InstallOperation::class);

        $operation
            ->expects(self::once())
            ->method('getPackage')
            ->willReturn($package);

        $event = $this->createMock(PackageEvent::class);
        $event
            ->expects(self::once())
            ->method('getOperation')
            ->willReturn($operation);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);
        $foxy->initOnInstall($event);

        self::assertTrue(
            $this->getFoxyProperty($foxy, 'initialized'),
            'Initialization flag must be set.',
        );
    }

    /**
     * @throws ParsingException
     */
    public function testActivateOnInstallIgnoresDifferentPackage(): void
    {
        $package = $this->createMock(Package::class);

        $package
            ->expects(self::once())
            ->method('getName')
            ->willReturn('vendor/package');

        $operation = $this->createMock(InstallOperation::class);

        $operation
            ->expects(self::once())
            ->method('getPackage')
            ->willReturn($package);

        $event = $this->createMock(PackageEvent::class);

        $event
            ->expects(self::once())
            ->method('getOperation')
            ->willReturn($operation);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);
        $foxy->initOnInstall($event);

        self::assertFalse(
            $this->getFoxyProperty($foxy, 'initialized'),
            'Initialization flag must remain unset.',
        );
    }

    /**
     * @throws ParsingException
     */
    public function testActivateOnInstallIgnoresNonInstallOperation(): void
    {
        $operation = $this->createMock(OperationInterface::class);
        $event = $this->createMock(PackageEvent::class);

        $event
            ->expects(self::once())
            ->method('getOperation')
            ->willReturn($operation);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);
        $foxy->initOnInstall($event);

        self::assertFalse(
            $this->getFoxyProperty($foxy, 'initialized'),
            'Initialization flag must remain unset.',
        );
    }

    /**
     * @throws ParsingException|ReflectionException
     */
    #[DataProviderExternal(FoxyProvider::class, 'registryUrls')]
    public function testActivatePassesRegistryUrlToNativeRegistry(array $config, string $expected): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'native', 'run-asset-manager' => false, ...$config]]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        self::assertSame(
            $expected,
            $this->getObjectProperty($this->getNativeRegistry($foxy), 'url'),
            'The registry must use the configured URL.',
        );
    }

    public function testActivateRejectsUnsupportedComposerVersion(): void
    {
        $foxy = new Foxy();

        (new ReflectionClass($foxy))->getProperty('composerVersion')->setValue($foxy, '2.9.0');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::UTIL_COMPOSER_VERSION_UNSUPPORTED->getMessage(Foxy::REQUIRED_COMPOSER_VERSION, '2.9.0'),
        );

        $foxy->activate($this->composer, $this->io);
    }

    /**
     * @throws ParsingException
     */
    public function testActivateResolvesDenoManager(): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'deno', 'run-asset-manager' => false]]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        $this->resolveAssetManager($foxy);

        self::assertInstanceOf(
            DenoManager::class,
            $this->getFoxyProperty($foxy, 'assetManager'),
            'Deno manager must be selected.',
        );
    }

    /**
     * @throws ParsingException
     */
    public function testActivateResolvesNativeManager(): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'native', 'run-asset-manager' => false]]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        $this->resolveAssetManager($foxy);

        self::assertInstanceOf(
            NativeManager::class,
            $this->getFoxyProperty($foxy, 'assetManager'),
            'The native manager must be selected.',
        );
        self::assertSame(
            getcwd() . DIRECTORY_SEPARATOR . 'package.json',
            $this->getObjectProperty($this->getFoxyProperty($foxy, 'assetFallback'), 'path'),
            'The fallback must target the root manifest.',
        );
    }

    /**
     * @throws ParsingException|ReflectionException
     */
    public function testActivateSharesComposerHttpDownloaderWithNativeRegistry(): void
    {
        $httpDownloader = $this->createMock(HttpDownloader::class);
        $loop = $this->createMock(Loop::class);

        $loop
            ->expects(self::once())
            ->method('getHttpDownloader')
            ->willReturn($httpDownloader);
        $this->composer
            ->method('getLoop')
            ->willReturn($loop);
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'native', 'run-asset-manager' => false]]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        self::assertSame(
            $httpDownloader,
            $this->getObjectProperty($this->getNativeRegistry($foxy), 'httpDownloader'),
            'Registry must use the downloader of the Composer loop.',
        );
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

        $reflection = new ReflectionClass($foxy);

        self::assertFalse(
            $reflection->getProperty('assetManager')->isInitialized($foxy),
            'Uninitialized manager must remain undiscovered.',
        );
        self::assertFalse(
            $reflection->getProperty('assetManagerFinder')->isInitialized($foxy),
            'Disabled plugin must not build the manager finder.',
        );
        self::assertFalse(
            $reflection->getProperty('composerFallback')->isInitialized($foxy),
            'Disabled plugin must not build the Composer fallback.',
        );
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

            $this->resolveAssetManager($foxy);

            $assetFallbackProperty = $foxyReflection->getProperty('assetFallback');
            $assetFallback = $assetFallbackProperty->getValue($foxy);

            $fallbackReflection = new ReflectionClass($assetFallback);

            $pathProperty = $fallbackReflection->getProperty('path');

            self::assertSame(
                'stub-package.json',
                $pathProperty->getValue($assetFallback),
                'Fallback path must use the package name.',
            );
        } finally {
            $assetManagersProperty->setValue(null, $originalAssetManagers);
        }
    }

    public function testAutomaticManagerDiscoveryDoesNotProbeBinariesWhenExecutionIsDisabled(): void
    {
        $config = new FoxyConfig(
            [],
            [
                'root-package-json-dir' => __DIR__ . '/Fixtures/package/global',
                'run-asset-manager' => false,
            ],
        );
        $executor = $this->createMock(ProcessExecutor::class);

        $executor
            ->expects(self::never())
            ->method('execute');

        $fs = $this->createMock(Filesystem::class);
        $foxy = new Foxy();
        $reflection = new ReflectionClass($foxy);

        $reflection->getProperty('config')->setValue($foxy, $config);
        $reflection->getProperty('io')->setValue($foxy, $this->io);
        $reflection->getProperty('fs')->setValue($foxy, $fs);
        $reflection->getProperty('assetManagerFinder')->setValue(
            $foxy,
            $reflection->getMethod('createAssetManagerFinder')->invoke(
                $foxy,
                $this->composer,
                $this->io,
                $config,
                $executor,
                $fs,
            ),
        );

        $manager = $this->resolveAssetManager($foxy);

        self::assertInstanceOf(
            NpmManager::class,
            $manager,
            'Npm manager must be selected.',
        );
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
        $config
            ->expects(self::never())
            ->method('isEnabled');

        $foxy = new Foxy();
        $reflection = new ReflectionClass($foxy);

        $reflection->getProperty('config')->setValue($foxy, $config);

        $isEnabled = $reflection->getMethod('isEnabled');

        self::assertTrue(
            $isEnabled->invoke($foxy),
            'Truthy integer must enable the plugin.',
        );
        self::assertFalse(
            $isEnabled->invoke($foxy, 'run-asset-manager'),
            'Disabled configuration must remain `false`.',
        );
    }

    public function testDeactivate(): void
    {
        $foxy = new Foxy();

        $foxy->deactivate($this->composer, $this->io);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @throws ParsingException
     */
    public function testGetAssetManagerResolvesOnce(): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'npm', 'run-asset-manager' => false]]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        $manager = $this->resolveAssetManager($foxy);
        $assetFallback = $this->getFoxyProperty($foxy, 'assetFallback');

        self::assertSame(
            $manager,
            $this->resolveAssetManager($foxy),
            'Second call must return the memoized manager.',
        );
        self::assertSame(
            $assetFallback,
            $this->getFoxyProperty($foxy, 'assetFallback'),
            'Second call must keep the asset fallback.',
        );
    }

    public function testGetSubscribedEvents(): void
    {
        self::assertSame(
            [
                InstallerEvents::PRE_OPERATIONS_EXEC => [['init', 100]],
                PackageEvents::POST_PACKAGE_INSTALL => [['initOnInstall', 100]],
                ScriptEvents::POST_INSTALL_CMD => [['solveAssets', 100]],
                ScriptEvents::POST_UPDATE_CMD => [['solveAssets', 100]],
            ],
            Foxy::getSubscribedEvents(),
            'Event names and listeners must match the plugin lifecycle.',
        );
    }

    /**
     * @throws ParsingException
     */
    #[DataProviderExternal(FoxyProvider::class, 'runAssetManagerValues')]
    public function testInitHonorsRunAssetManagerValues(mixed $value, bool $expectedValidation): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'npm', 'run-asset-manager' => $value]]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        $assetManager = $this->createNpmManagerMock();

        $assetManager
            ->expects($expectedValidation ? self::once() : self::never())
            ->method('validate');

        $this->injectAssetManagerFinder($foxy, $assetManager);

        $foxy->init();
    }

    /**
     * @throws ParsingException
     */
    public function testInitRunsOnlyOnce(): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'npm', 'run-asset-manager' => true]]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        $assetManager = $this->createNpmManagerMock();

        $assetManager
            ->expects(self::once())
            ->method('validate');

        $this->injectAssetManagerFinder($foxy, $assetManager);

        $foxy->init();
        $foxy->init();

        self::assertTrue(
            $this->getFoxyProperty($foxy, 'initialized'),
            'Flag must stay set after the second call.',
        );
    }

    #[DataProviderExternal(FoxyProvider::class, 'solveEvents')]
    public function testSolveAssets(string $eventName, bool $expectedUpdatable): void
    {
        $event = new Event($eventName, $this->composer, $this->io);

        $solver = $this->createMock(SolverInterface::class);

        $solver
            ->expects(self::once())
            ->method('setUpdatable')
            ->with($expectedUpdatable);
        $solver
            ->expects(self::once())
            ->method('solve')
            ->with($this->composer, $this->io);

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

        $solver
            ->expects(self::never())
            ->method('setUpdatable');
        $solver
            ->expects(self::never())
            ->method('solve');

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);
        $foxy->setSolver($solver);
        $foxy->solveAssets(new Event('solve_event_install', $this->composer, $this->io));
    }

    /**
     * @throws ParsingException
     */
    public function testThrowRuntimeExceptionWhenInitFindsUnknownManager(): void
    {
        $this->package
            ->expects(self::any())
            ->method('getConfig')
            ->willReturn(['foxy' => ['manager' => 'invalid_manager']]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_MANAGER_UNKNOWN->getMessage('invalid_manager'),
        );

        $foxy->init();
    }

    /**
     * @throws ParsingException
     */
    public function testThrowRuntimeExceptionWhenIntegerOneEnabledPluginFindsUnknownManager(): void
    {
        $this->package
            ->method('getConfig')
            ->willReturn(['foxy' => ['enabled' => 1, 'manager' => 'invalid_manager']]);

        $foxy = new Foxy();

        $foxy->activate($this->composer, $this->io);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_MANAGER_UNKNOWN->getMessage('invalid_manager'),
        );

        $foxy->init();
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
                fn($key, $flags = 0): bool|string|null => $this->composerSettings[$key] ?? match ($key) {
                    'cache-files-dir' => sys_get_temp_dir() . '/foxy-test-cache',
                    'vendor-dir' => getcwd() . '/vendor',
                    default => null,
                },
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

    /**
     * Returns an asset manager mock named `npm` that has no lock file.
     */
    private function createNpmManagerMock(): AssetManagerInterface&MockObject
    {
        $assetManager = $this->createMock(AssetManagerInterface::class);

        $assetManager->method('getName')->willReturn('npm');
        $assetManager->method('getPackageName')->willReturn('package.json');
        $assetManager->method('hasLockFile')->willReturn(false);

        return $assetManager;
    }

    private function getFoxyProperty(Foxy $foxy, string $name): mixed
    {
        return (new ReflectionClass($foxy))->getProperty($name)->getValue($foxy);
    }

    /**
     * Returns the registry wired into the native manager selected by the plugin.
     *
     * @throws ReflectionException
     */
    private function getNativeRegistry(Foxy $foxy): NpmRegistry
    {
        $manager = $this->resolveAssetManager($foxy);

        self::assertInstanceOf(
            NativeManager::class,
            $manager,
            'The native manager must be selected.',
        );

        $registry = $this->getObjectProperty($this->getObjectProperty($manager, 'installer'), 'registry');

        self::assertInstanceOf(
            NpmRegistry::class,
            $registry,
            'The installer must use the npm registry.',
        );

        return $registry;
    }

    private function getObjectProperty(object $object, string $name): mixed
    {
        return (new ReflectionClass($object))->getProperty($name)->getValue($object);
    }
    /**
     * Replaces the finder built by activation with one that holds only the given manager.
     */
    private function injectAssetManagerFinder(Foxy $foxy, AssetManagerInterface $assetManager): void
    {
        (new ReflectionClass($foxy))
            ->getProperty('assetManagerFinder')
            ->setValue($foxy, new AssetManagerFinder([$assetManager]));
    }

    /**
     * Invokes a private method of the plugin.
     */
    private function invokeFoxyMethod(Foxy $foxy, string $name): mixed
    {
        return (new ReflectionClass($foxy))->getMethod($name)->invoke($foxy);
    }

    /**
     * Resolves the asset manager through the plugin's private memoizing accessor.
     */
    private function resolveAssetManager(Foxy $foxy): AssetManagerInterface
    {
        return $this->invokeFoxyMethod($foxy, 'getAssetManager');
    }

}
