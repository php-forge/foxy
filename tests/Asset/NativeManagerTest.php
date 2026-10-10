<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Foxy\Asset\NativeManager;
use Foxy\Config\Config;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Fallback\FallbackInterface;
use Foxy\Native\NativeInstallerInterface;
use Foxy\Tests\Provider\NativeManagerProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Xepozz\InternalMocker\MockerState;

use function chdir;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function mkdir;
use function str_replace;
use function strtr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Unit tests for {@see NativeManager} selection, validation, install directory resolution, and installer orchestration.
 *
 * {@see NativeManagerProvider} for test case data providers.
 */
final class NativeManagerTest extends TestCase
{
    private const string INSTALLING = '<info>Installing frontend dependencies with the native manager</info>';

    private string $cwd = '';
    private FallbackInterface&MockObject $fallback;
    private NativeInstallerInterface&MockObject $installer;
    private IOInterface&MockObject $io;
    private string $oldCwd = '';

    public function testAddDependenciesWritesManifest(): void
    {
        $rootPackage = $this->createMock(RootPackageInterface::class);

        $rootPackage
            ->method('getLicense')
            ->willReturn([]);

        $this->manager(['run-asset-manager' => false])->addDependencies(
            $rootPackage,
            ['@composer-asset/foo--bar' => 'path/foo/bar/package.json'],
        );

        self::assertStringContainsString(
            '"@composer-asset/foo--bar": "file:./path/foo/bar"',
            (string) file_get_contents("{$this->cwd}/package.json"),
            'The merged dependency must be written.',
        );
    }

    public function testIsAvailableRequiresLockFileAndResolvesManager(): void
    {
        $config = new Config(['manager-timeout' => ['native' => 5]]);
        $manager = new NativeManager($this->io, $config, new Filesystem(), $this->installer);

        self::assertNull(
            $config->get('manager-timeout'),
            'No manager-keyed value applies before resolution.',
        );
        self::assertFalse(
            $manager->isAvailable(),
            'A missing lock file must not make the manager available.',
        );
        self::assertSame(
            5,
            $config->get('manager-timeout'),
            'The manager must be recorded as resolved.',
        );

        file_put_contents("{$this->cwd}/foxy.lock", '{}');

        self::assertTrue(
            $manager->isAvailable(),
            'An existing lock file must make the manager available.',
        );
    }

    public function testIsInstalledFollowsConfiguredDirectory(): void
    {
        $manager = $this->manager(['native-install-dir' => 'vendor/npm-asset']);

        $this->markInstalled();

        self::assertFalse(
            $manager->isInstalled(),
            'An existing `node_modules` must not count.',
        );

        mkdir("{$this->cwd}/vendor/npm-asset", 0o777, true);

        self::assertTrue(
            $manager->isInstalled(),
            'The configured directory and the manifest must count.',
        );

        unlink("{$this->cwd}/package.json");

        self::assertFalse(
            $manager->isInstalled(),
            'The manifest must exist too.',
        );
    }

    public function testNameAccessorsReturnNativeValues(): void
    {
        $manager = $this->manager();

        self::assertSame(
            'foxy.lock',
            $manager->getLockPackageName(),
            'The lock file must be `foxy.lock`.',
        );
        self::assertSame(
            'native',
            $manager->getName(),
            'The manager name must be `native`.',
        );
        self::assertSame(
            'package.json',
            $manager->getPackageName(),
            'The manifest must be `package.json`.',
        );
        self::assertSame(
            '*',
            $manager->getVersionConstraint(),
            'Any version must be accepted.',
        );
    }

    public function testRunForcesInstallWhenUpdatesAreDisabled(): void
    {
        $this->markInstalled();

        $this->expectInstall("{$this->cwd}/", false, self::INSTALLING);

        $manager = $this->manager(['run-asset-manager' => true]);

        $manager->setUpdatable(false);

        self::assertSame(
            0,
            $manager->run(),
            'A successful run must exit with `0`.',
        );
    }

    #[DataProviderExternal(NativeManagerProvider::class, 'installDirectories')]
    public function testRunInstallsIntoConfiguredDirectory(
        int|string|null $value,
        string|null $rootPackageDir,
        string $expected,
    ): void {
        $root = $this->cwd;

        if (null !== $rootPackageDir) {
            $root .= "/{$rootPackageDir}";

            mkdir($root);
        }

        $this->installer
            ->expects(self::once())
            ->method('install')
            ->with("{$root}/package.json", "{$root}/foxy.lock", str_replace('{cwd}', $this->cwd, $expected), false);

        $this->manager(
            ['run-asset-manager' => true, 'native-install-dir' => $value, 'root-package-json-dir' => $rootPackageDir],
        )->run();
    }

    public function testRunInstallsIntoConfiguredRootPackageDir(): void
    {
        mkdir("{$this->cwd}/web");

        $this->expectInstall("{$this->cwd}/web/", false, self::INSTALLING);

        self::assertSame(
            0,
            $this->manager(['run-asset-manager' => true, 'root-package-json-dir' => 'web'])->run(),
            'A successful run must exit with `0`.',
        );
    }

    public function testRunInstallsIntoCurrentDirectory(): void
    {
        $this->expectInstall("{$this->cwd}/", false, self::INSTALLING);

        self::assertSame(
            0,
            $this->manager(['run-asset-manager' => true])->run(),
            'A successful run must exit with `0`.',
        );
    }

    public function testRunRestoresFallbackAndRethrowsInstallerFailure(): void
    {
        $failure = new RuntimeException('Install failed.');

        $this->installer
            ->expects(self::once())
            ->method('install')
            ->willThrowException($failure);
        $this->fallback
            ->expects(self::once())
            ->method('restore');

        try {
            $this->manager(['run-asset-manager' => true])->run();

            self::fail('Expected the installation to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                $failure,
                $exception,
                'The installer exception must be rethrown unchanged.',
            );
        }
    }

    public function testRunReturnsZeroWithoutInstallingWhenDisabled(): void
    {
        $this->installer
            ->expects(self::never())
            ->method('install');
        $this->io
            ->expects(self::never())
            ->method('write');

        self::assertSame(
            0,
            $this->manager(['run-asset-manager' => false])->run(),
            'A disabled run must exit with `0`.',
        );
    }

    public function testRunUpdatesWhenInstalled(): void
    {
        $this->markInstalled();

        $this->expectInstall(
            "{$this->cwd}/",
            true,
            '<info>Updating frontend dependencies with the native manager</info>',
        );

        self::assertSame(
            0,
            $this->manager(['run-asset-manager' => true])->run(),
            'A successful run must exit with `0`.',
        );
    }

    #[DataProviderExternal(NativeManagerProvider::class, 'invalidInstallDirectories')]
    public function testThrowRuntimeExceptionForInvalidInstallDirectory(string $value, string $expected): void
    {
        $placeholders = ['{cwd}' => $this->cwd, '{parent}' => dirname($this->cwd)];

        $this->installer
            ->expects(self::never())
            ->method('install');
        $this->io
            ->expects(self::never())
            ->method('write');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_INSTALL_DIR_INVALID->getMessage(strtr($expected, $placeholders)),
        );

        $this->manager(['run-asset-manager' => true, 'native-install-dir' => strtr($value, $placeholders)])->run();
    }

    public function testThrowRuntimeExceptionWhenFallbackRestoreFails(): void
    {
        $failure = new RuntimeException('Install failed.');

        $this->installer
            ->method('install')
            ->willThrowException($failure);
        $this->fallback
            ->expects(self::once())
            ->method('restore')
            ->willThrowException(new RuntimeException('Fallback failed.'));

        try {
            $this->manager(['run-asset-manager' => true])->run();

            self::fail('Expected the fallback restoration to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_MANAGER_FALLBACK_RESTORE_FAILED->getMessage('Fallback failed.'),
                $exception->getMessage(),
                'The fallback failure must be reported.',
            );
            self::assertSame(
                $failure,
                $exception->getPrevious(),
                'The installer exception must be preserved.',
            );
        }
    }

    public function testThrowRuntimeExceptionWhenRootPackageDirIsMissing(): void
    {
        $this->installer
            ->expects(self::never())
            ->method('install');
        $this->io
            ->expects(self::never())
            ->method('write');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_ROOT_PACKAGE_DIR_MISSING->getMessage("{$this->cwd}/missing"),
        );

        $this->manager(['run-asset-manager' => true, 'root-package-json-dir' => 'missing'])->run();
    }

    public function testThrowRuntimeExceptionWhenZlibIsMissing(): void
    {
        MockerState::addCondition('Foxy\\Asset', 'extension_loaded', ['zlib'], false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_EXTENSION_MISSING->getMessage('zlib'),
        );

        $this->manager()->validate();
    }

    public function testThrowRuntimeExceptionWhenZlibIsMissingDuringRun(): void
    {
        MockerState::addCondition('Foxy\\Asset', 'extension_loaded', ['zlib'], false);

        $this->installer
            ->expects(self::never())
            ->method('install');
        $this->io
            ->expects(self::never())
            ->method('write');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_EXTENSION_MISSING->getMessage('zlib'),
        );

        $this->manager(['run-asset-manager' => true])->run();
    }

    public function testValidateAcceptsLoadedZlib(): void
    {
        $this->manager()->validate();

        $this->expectNotToPerformAssertions();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fallback = $this->createMock(FallbackInterface::class);
        $this->installer = $this->createMock(NativeInstallerInterface::class);
        $this->io = $this->createMock(IOInterface::class);
        $this->oldCwd = (string) getcwd();

        $directory = sys_get_temp_dir() . '/' . uniqid('foxy_native_manager_test_', true);

        mkdir($directory, 0o777, true);
        chdir($directory);

        $this->cwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);

        (new Filesystem())->removeDirectory($this->cwd);
    }

    /**
     * Expects one installation into the directory and the given progress line.
     *
     * @param string $directory Root package directory with a trailing slash.
     */
    private function expectInstall(string $directory, bool $update, string $line): void
    {
        $this->installer
            ->expects(self::once())
            ->method('install')
            ->with("{$directory}package.json", "{$directory}foxy.lock", "{$directory}node_modules", $update);
        $this->io
            ->expects(self::once())
            ->method('write')
            ->with($line);
    }

    /**
     * @param array<string, mixed> $config Foxy configuration.
     */
    private function manager(array $config = []): NativeManager
    {
        return new NativeManager($this->io, new Config($config), new Filesystem(), $this->installer, $this->fallback);
    }

    private function markInstalled(): void
    {
        file_put_contents("{$this->cwd}/package.json", '{}');
        mkdir("{$this->cwd}/node_modules");
    }
}
