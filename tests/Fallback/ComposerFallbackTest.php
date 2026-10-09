<?php

declare(strict_types=1);

namespace Foxy\Tests\Fallback;

use Composer\Composer;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\Installer;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryManager;
use Composer\Util\Filesystem;
use Exception;
use Foxy\Config\Config;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Fallback\ComposerFallback;
use Foxy\Tests\Provider\ComposerFallbackProvider;
use Foxy\Util\LockerUtil;
use JsonException;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Input\InputInterface;

use function chdir;
use function file_get_contents;
use function file_put_contents;
use function json_decode;

use const DIRECTORY_SEPARATOR;

/**
 * Unit tests for {@see ComposerFallback} Composer lock snapshot and restore.
 *
 * {@see ComposerFallbackProvider} for test case data providers.
 */
final class ComposerFallbackTest extends TestCase
{
    private Composer|MockObject|null $composer = null;
    private ComposerFallback|null $composerFallback = null;
    private Config|null $config = null;
    private string|null $cwd = '';
    private Filesystem|MockObject|null $fs = null;
    private InputInterface|MockObject|null $input = null;
    private Installer|MockObject|null $installer = null;
    private IOInterface|MockObject|null $io = null;
    private string|null $oldCwd = '';
    private \Symfony\Component\Filesystem\Filesystem|null $sfs = null;

    public function testFailedSubsequentSaveInvalidatesPreviousSnapshot(): void
    {
        file_put_contents($this->cwd . '/composer.json', '{}');

        $vendorDir = "{$this->cwd}/vendor";

        $composerConfig = $this->createMock(\Composer\Config::class);

        $composerConfig
            ->method('get')
            ->willReturnCallback(
                static fn($key, $default = null) => 'vendor-dir' === $key ? $vendorDir : $default,
            );

        $configCalls = 0;

        $this->composer
            ->expects(self::exactly(2))
            ->method('getConfig')
            ->willReturnCallback(
                static function () use (&$configCalls, $composerConfig): \Composer\Config {
                    if (2 === ++$configCalls) {
                        throw new \RuntimeException('Unable to read Composer configuration.');
                    }

                    return $composerConfig;
                },
            );

        $installationManager = $this->createMock(InstallationManager::class);

        $this->composer
            ->expects(self::once())
            ->method('getInstallationManager')
            ->willReturn($installationManager);
        $this->composer
            ->expects(self::never())
            ->method('getLocker');

        $this->composerFallback->save();

        try {
            $this->composerFallback->save();

            self::fail(
                'Expected the subsequent snapshot to fail.',
            );
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Unable to read Composer configuration.',
                $exception->getMessage(),
                'Exception message must match the thrown exception.',
            );
        }

        file_put_contents($this->cwd . '/composer.lock', '{}');

        $this->io
            ->expects(self::never())
            ->method('write');
        $this->fs
            ->expects(self::never())
            ->method('remove');

        $this->composerFallback->restore();

        self::assertFileExists(
            "{$this->cwd}/composer.lock",
            'Composer lock file must exist after restore.',
        );
    }

    public function testIntegerOneEnablesFallback(): void
    {
        $config = new Config(['fallback-composer' => 1]);

        $this->composerFallback = new ComposerFallback(
            $this->composer,
            $this->io,
            $config,
            $this->input,
            $this->fs,
            $this->installer,
        );

        $this->setupNoLockEnvironment();
        $this->composerFallback->save();

        file_put_contents("{$this->cwd}/composer.lock", '{}');

        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->willReturnCallback(
                function (string $path): bool {
                    $this->sfs->remove($path);

                    return true;
                }
            );

        $this->composerFallback->restore();

        self::assertFileDoesNotExist(
            "{$this->cwd}/composer.lock",
            'Composer lock file must not exist after restore.',
        );
    }

    /**
     * @throws Exception|JsonException
     */
    #[DataProviderExternal(ComposerFallbackProvider::class, 'lockedPackages')]
    public function testRestore(array $packages): void
    {
        $this->setupRestoreEnvironment(
            $packages,
            static fn($option): bool|null => 'verbose' === $option ? false : null,
        );

        $this->fs
            ->expects(self::never())
            ->method('remove');

        $this->expectInstallerRun();
        $this->composerFallback->save();
        $this->composerFallback->restore();
    }

    public function testRestoreAcceptsAnAlreadyRemovedPathWhenFilesystemReportsFailure(): void
    {
        $this->setupNoLockEnvironment();

        $this->composerFallback->save();

        file_put_contents("{$this->cwd}/composer.lock", '{}');

        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->with('./composer.lock')
            ->willReturnCallback(
                function (string $path): bool {
                    $this->sfs->remove($path);

                    return false;
                }
            );

        $this->composerFallback->restore();

        self::assertFileDoesNotExist(
            "{$this->cwd}/composer.lock",
            'Composer lock file must not exist after restore.',
        );
    }

    /**
     * @throws Exception
     */
    public function testRestoreBeforeSaveDoesNothing(): void
    {
        $this->io
            ->expects(self::never())
            ->method('write');
        $this->composer
            ->expects(self::never())
            ->method('getLocker');
        $this->fs
            ->expects(self::never())
            ->method('remove');
        $this->installer
            ->expects(self::never())
            ->method('setRunScripts');
        $this->installer
            ->expects(self::never())
            ->method('run');

        $this->composerFallback->restore();
    }

    /**
     * @throws Exception|JsonException
     */
    public function testRestoreCombinesOptimizeAutoloaderSourcesIndependently(): void
    {
        $this->setupRestoreEnvironment(
            [],
            static fn(string $option): bool|null => 'optimize-autoloader' === $option ? true : null,
            configOptions: ['optimize-autoloader' => false],
        );

        $this->expectInstallerRun();
        $this->composerFallback->save();
        $this->composerFallback->restore();

        self::assertFalse(
            $this->getInstallerProperty('classMapAuthoritative'),
            'Class map authoritative should be false after restore.',
        );
        self::assertTrue(
            $this->getInstallerProperty('optimizeAutoloader'),
            'Optimize autoloader should be true after restore.',
        );
    }

    /**
     * @throws Exception|JsonException
     */
    public function testRestorePreservesLockMetadata(): void
    {
        $packages = [['name' => 'foo/bar', 'version' => '1.0.0.0']];
        $devPackages = [['name' => 'foo/dev', 'version' => '2.0.0.0']];
        $platform = ['php' => '^8.2'];
        $platformDev = ['ext-json' => '*'];
        $stabilityFlags = ['foo/bar' => 10];
        $platformOverrides = ['php' => '8.3.4'];

        $this->setupRestoreEnvironment(
            $packages,
            static fn($option): bool|null => 'verbose' === $option ? false : null,
            lockOptions: [
                'packages-dev' => $devPackages,
                'platform' => $platform,
                'platform-dev' => $platformDev,
                'minimum-stability' => 'beta',
                'stability-flags' => $stabilityFlags,
                'prefer-lowest' => true,
                'platform-overrides' => $platformOverrides,
            ],
        );

        $this->expectInstallerRun();
        $this->composerFallback->save();
        $this->composerFallback->restore();

        $restoredLock = json_decode(
            file_get_contents("{$this->cwd}/composer.lock"),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'foo/bar',
            $restoredLock['packages'][0]['name'],
            'Package name should be foo/bar after restore.',
        );
        self::assertSame(
            'foo/dev',
            $restoredLock['packages-dev'][0]['name'],
            'Dev package name should be foo/dev after restore.',
        );
        self::assertSame(
            $platform,
            $restoredLock['platform'],
            'Platform should be preserved after restore.',
        );
        self::assertSame(
            $platformDev,
            $restoredLock['platform-dev'],
            'Platform-dev should be preserved after restore.',
        );
        self::assertSame(
            'beta',
            $restoredLock['minimum-stability'],
            'Minimum stability should be beta after restore.',
        );
        self::assertSame(
            $stabilityFlags,
            $restoredLock['stability-flags'],
            'Stability flags should be preserved after restore.',
        );
        self::assertTrue(
            $restoredLock['prefer-stable'],
            'Prefer stable should be true after restore.',
        );
        self::assertTrue(
            $restoredLock['prefer-lowest'],
            'Prefer lowest should be true after restore.',
        );
        self::assertSame(
            $platformOverrides,
            $restoredLock['platform-overrides'],
            'Platform overrides should be preserved after restore.',
        );
    }

    /**
     * @throws Exception|JsonException
     */
    public function testRestorePreservesRawAliases(): void
    {
        $aliases = [
            [
                'package' => 'foo/bar',
                'version' => 'dev-feature',
                'alias' => '1.0.x-dev',
                'alias_normalized' => '1.0.9999999.9999999-dev',
            ],
        ];

        $this->setupRestoreEnvironment(
            [['name' => 'foo/bar', 'version' => 'dev-feature']],
            static fn($option): bool|null => 'verbose' === $option ? false : null,
            $aliases,
        );

        $this->fs
            ->expects(self::never())
            ->method('remove');

        $this->expectInstallerRun();
        $this->composerFallback->save();
        $this->composerFallback->restore();

        $restoredLock = json_decode(
            file_get_contents($this->cwd . '/composer.lock'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            $aliases,
            $restoredLock['aliases'],
            'Aliases should be preserved after restore.',
        );
    }

    /**
     * @throws Exception
     */
    public function testRestorePreservesVendorDirectoryThatExistedBeforeSavingWithoutLock(): void
    {
        $vendorDir = "{$this->cwd}/vendor";
        $sentinel = "{$vendorDir}/keep.txt";

        $this->sfs->mkdir($vendorDir);

        file_put_contents($sentinel, 'keep');

        $this->setupNoLockEnvironment();
        $this->composerFallback->save();

        file_put_contents($this->cwd . '/composer.lock', '{}');

        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->with('./composer.lock')
            ->willReturnCallback(
                function (string $path): bool {
                    $this->sfs->remove($path);

                    return true;
                }
            );
        $this->installer
            ->expects(self::never())
            ->method('setRunScripts');
        $this->installer
            ->expects(self::never())
            ->method('run');

        $this->composerFallback->restore();

        self::assertFileExists(
            $sentinel,
            'Sentinel file should exist after restore.'
        );
    }

    /**
     * @throws Exception|JsonException
     */
    #[DataProviderExternal(ComposerFallbackProvider::class, 'installerBooleanOptions')]
    public function testRestorePropagatesInstallerBooleanOptions(
        mixed $inputValue,
        mixed $configValue,
        bool $expected,
    ): void {
        $optionNames = ['apcu-autoloader', 'classmap-authoritative', 'optimize-autoloader'];

        $configOptions = array_fill_keys($optionNames, $configValue);

        $this->setupRestoreEnvironment(
            [],
            static fn(string $option): mixed => in_array($option, $optionNames, true) ? $inputValue : null,
            configOptions: $configOptions,
        );

        $this->expectInstallerRun();
        $this->composerFallback->save();
        $this->composerFallback->restore();

        self::assertSame(
            $expected,
            $this->getInstallerProperty('apcuAutoloader'),
            'Installer option "apcu-autoloader" should be propagated correctly.',
        );
        self::assertSame(
            $expected,
            $this->getInstallerProperty('classMapAuthoritative'),
            'Installer option "classmap-authoritative" should be propagated correctly.',
        );
        self::assertSame(
            $expected,
            $this->getInstallerProperty('optimizeAutoloader'),
            'Installer option "optimize-autoloader" should be propagated correctly.',
        );
    }

    /**
     * @throws Exception|JsonException
     */
    public function testRestorePropagatesInverseAndScalarInstallerOptions(): void
    {
        $this->setupRestoreEnvironment(
            [],
            static fn(string $option): mixed => match ($option) {
                'no-autoloader', 'no-dev' => 1,
                'verbose' => 1,
                default => null,
            },
        );

        $this->expectInstallerRun();
        $this->composerFallback->save();
        $this->composerFallback->restore();

        self::assertFalse(
            $this->getInstallerProperty('devMode'),
            'Installer option "no-dev" should be propagated correctly.',
        );
        self::assertFalse(
            $this->getInstallerProperty('dumpAutoloader'),
            'Installer option "no-autoloader" should be propagated correctly.',
        );
        self::assertTrue(
            $this->getInstallerProperty('verbose'),
            'Installer option "verbose" should be propagated correctly.',
        );
    }

    /**
     * @throws Exception
     */
    public function testRestoreRemovesOnlyStateCreatedAfterSavingWithoutLock(): void
    {
        $vendorDir = $this->setupNoLockEnvironment(2);

        $this->composerFallback->save();

        file_put_contents("{$this->cwd}/composer.lock", '{}');

        $this->sfs->mkdir($vendorDir);

        $removed = [];

        $this->fs
            ->expects(self::exactly(2))
            ->method('remove')
            ->willReturnCallback(
                function (string $path) use (&$removed): bool {
                    $removed[] = $path;

                    $this->sfs->remove($path);

                    return true;
                }
            );
        $this->installer
            ->expects(self::never())
            ->method('setRunScripts');
        $this->installer
            ->expects(self::never())
            ->method('run');

        $this->composerFallback->restore();
        $this->composerFallback->restore();

        self::assertSame(
            ['./composer.lock', $vendorDir],
            $removed,
            'Removed paths should match the expected order.',
        );
        self::assertFileDoesNotExist(
            "{$this->cwd}/composer.lock",
            'Composer lock file should have been removed.',
        );
        self::assertDirectoryDoesNotExist(
            $vendorDir,
            'Vendor directory should have been removed.',
        );
    }

    /**
     * @throws Exception
     */
    public function testRestoreThrowsWhenCreatedLockCannotBeRemoved(): void
    {
        $this->setupNoLockEnvironment();

        $this->composerFallback->save();

        file_put_contents("{$this->cwd}/composer.lock", '{}');

        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->with('./composer.lock')
            ->willReturn(false);
        $this->installer
            ->expects(self::never())
            ->method('setRunScripts');
        $this->installer
            ->expects(self::never())
            ->method('run');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::FALLBACK_COMPOSER_REMOVE_FAILED->getMessage('./composer.lock'),
        );

        $this->composerFallback->restore();
    }

    /**
     * @throws Exception
     */
    public function testRestoreThrowsWhenCreatedVendorDirectoryCannotBeRemoved(): void
    {
        $vendorDir = $this->setupNoLockEnvironment();

        $this->composerFallback->save();
        $this->sfs->mkdir($vendorDir);

        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->with($vendorDir)
            ->willReturn(false);
        $this->installer
            ->expects(self::never())
            ->method('setRunScripts');
        $this->installer
            ->expects(self::never())
            ->method('run');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::FALLBACK_COMPOSER_REMOVE_FAILED->getMessage($vendorDir),
        );

        $this->composerFallback->restore();
    }

    /**
     * @throws Exception|JsonException
     */
    public function testRestoreThrowsWhenInstallerFails(): void
    {
        $this->setupRestoreEnvironment(
            [['name' => 'foo/bar', 'version' => '1.0.0.0']],
            static fn($option): bool|null => 'verbose' === $option ? false : null,
        );

        $this->expectInstallerRun(7);

        $this->composerFallback->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::FALLBACK_COMPOSER_RESTORE_FAILED->getMessage(7),
        );

        $this->composerFallback->restore();
    }

    /**
     * @throws Exception|JsonException
     */
    public function testRestoreUsesDisabledDefaultsForMissingLockAndPlatformOptions(): void
    {
        $this->setupRestoreEnvironment(
            [],
            static fn(string $option): null => null,
        );

        $lockPath = "{$this->cwd}/composer.lock";

        $lock = json_decode(file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);

        unset($lock['prefer-lowest'], $lock['prefer-stable']);
        file_put_contents($lockPath, json_encode($lock, JSON_THROW_ON_ERROR));

        $this->expectInstallerRun();
        $this->composerFallback->save();
        $this->composerFallback->restore();

        $restoredLock = json_decode(file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse(
            $restoredLock['prefer-lowest'],
            "Expected prefer-lowest to be 'false'",
        );
        self::assertFalse(
            $restoredLock['prefer-stable'],
            "Expected prefer-stable to be 'false'",
        );
        self::assertTrue(
            $this->getInstallerProperty('devMode'),
            "Expected devMode to be 'true'",
        );
        self::assertTrue(
            $this->getInstallerProperty('dumpAutoloader'),
            "Expected dumpAutoloader to be 'true'",
        );

        $filter = $this->getInstallerProperty('platformRequirementFilter');

        self::assertInstanceOf(
            PlatformRequirementFilterInterface::class,
            $filter,
            'Expected platform requirement filter to be an instance of PlatformRequirementFilterInterface',
        );
        self::assertFalse(
            $filter->isIgnored('php'),
            "Expected 'php' platform requirement to be ignored to be 'false'",
        );
    }

    /**
     * @throws Exception
     */
    public function testRestoreWithDisableOption(): void
    {
        $config = new Config(['fallback-composer' => false]);
        $composerFallback = new ComposerFallback($this->composer, $this->io, $config, $this->input);

        $this->io
            ->expects(self::never())
            ->method('write');

        $composerFallback->restore();
    }

    /**
     * @throws Exception|JsonException
     */
    #[DataProviderExternal(ComposerFallbackProvider::class, 'ignorePlatformReqOptions')]
    public function testRestoreWithIgnorePlatformReq(string $optionName, mixed $optionValue): void
    {
        $packages = [['name' => 'foo/bar', 'version' => '1.0.0.0']];

        $this->setupRestoreEnvironment(
            $packages,
            fn($option): mixed => match ($option) {
                'ignore-platform-reqs' => null,
                $optionName => $optionValue,
                'verbose' => false,
                default => null,
            },
        );

        $this->expectInstallerRun();
        $this->composerFallback->save();
        $this->composerFallback->restore();

        $filter = $this->getInstallerProperty('platformRequirementFilter');

        self::assertInstanceOf(
            PlatformRequirementFilterInterface::class,
            $filter,
            'Expected platform requirement filter to be an instance of PlatformRequirementFilterInterface',
        );
        self::assertTrue(
            $filter->isIgnored('php'),
            "Expected 'php' platform requirement to be ignored to be 'true'",
        );
        self::assertTrue(
            $filter->isIgnored('ext-json'),
            "Expected 'ext-json' platform requirement to be ignored to be 'true'",
        );
    }

    /**
     * @throws Exception|JsonException
     */
    #[DataProviderExternal(ComposerFallbackProvider::class, 'ignorePlatformReqsOptions')]
    public function testRestoreWithIgnorePlatformReqs(string $optionName, mixed $optionValue): void
    {
        $packages = [['name' => 'foo/bar', 'version' => '1.0.0.0']];

        $this->setupRestoreEnvironment(
            $packages,
            fn($option): mixed => match ($option) {
                $optionName => $optionValue,
                'verbose' => false,
                default => null,
            },
        );

        $this->expectInstallerRun();
        $this->composerFallback->save();
        $this->composerFallback->restore();

        $filter = $this->getInstallerProperty('platformRequirementFilter');

        self::assertInstanceOf(
            PlatformRequirementFilterInterface::class,
            $filter,
            'Expected platform requirement filter to be an instance of PlatformRequirementFilterInterface',
        );
        self::assertTrue(
            $filter->isIgnored('php'),
            "Expected 'php' platform requirement to be ignored to be 'true'",
        );
        self::assertTrue(
            $filter->isIgnored('ext-json'),
            "Expected 'ext-json' platform requirement to be ignored to be 'true'",
        );
    }

    public function testRestoreWrapsRemoveException(): void
    {
        $this->setupNoLockEnvironment();

        $this->composerFallback->save();

        file_put_contents("{$this->cwd}/composer.lock", '{}');

        $failure = new \RuntimeException(
            'Remove failed.',
        );

        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->with('./composer.lock')
            ->willThrowException($failure);
        $this->installer
            ->expects(self::never())
            ->method('setRunScripts');
        $this->installer
            ->expects(self::never())
            ->method('run');

        try {
            $this->composerFallback->restore();
            self::fail(
                'Expected the remove exception to be wrapped.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::FALLBACK_COMPOSER_REMOVE_FAILED->getMessage('./composer.lock'),
                $exception->getMessage(),
                'Message must name the lock file that could not be removed.',
            );
            self::assertSame(
                0,
                $exception->getCode(),
                "Expected the exception code to be '0'.",
            );
            self::assertSame(
                $failure,
                $exception->getPrevious(),
                'Expected the previous exception to be the original failure.',
            );
        }
    }

    /**
     * @throws JsonException
     */
    #[DataProviderExternal(ComposerFallbackProvider::class, 'snapshotScenarios')]
    public function testSave(bool $withLockFile): void
    {
        $rm = $this->createMock(RepositoryManager::class);

        $this->composer
            ->expects(self::any())
            ->method('getRepositoryManager')
            ->willReturn($rm);

        $im = $this->createMock(InstallationManager::class);

        $this->composer
            ->expects(self::any())
            ->method('getInstallationManager')
            ->willReturn($im);

        $config = $this->createMock(\Composer\Config::class);

        $config
            ->method('get')
            ->willReturn("{$this->cwd}/vendor");
        $this->composer
            ->method('getConfig')
            ->willReturn($config);

        file_put_contents("{$this->cwd}/composer.json", '{}');

        if ($withLockFile) {
            file_put_contents(
                "{$this->cwd}/composer.lock",
                json_encode(['content-hash' => 'HASH_VALUE'], JSON_THROW_ON_ERROR),
            );
        }

        self::assertInstanceOf(
            ComposerFallback::class,
            $this->composerFallback->save(),
            'Expected the save method to return an instance of ComposerFallback.',
        );
    }

    /**
     * @throws Exception|JsonException
     */
    public function testSaveKeepsLockDataRawUntilRestoreAndReusesHydratedPackages(): void
    {
        $packages = [['name' => 'foo/bar', 'version' => '1.0.0.0']];

        $this->setupRestoreEnvironment(
            $packages,
            static fn($option): bool|null => 'verbose' === $option ? false : null,
            [],
            2,
        );

        $this->fs
            ->expects(self::never())
            ->method('remove');
        $this->expectInstallerRun(0, 2);

        $this->composerFallback->save();

        $reflection = new ReflectionClass($this->composerFallback);

        $lockProperty = $reflection->getProperty('lock');
        $hydratedLockProperty = $reflection->getProperty('hydratedLock');
        $rawLock = $lockProperty->getValue($this->composerFallback);

        self::assertIsArray(
            $rawLock,
            'Expected the raw lock data to be an array.',
        );
        self::assertIsArray(
            $rawLock['packages'][0],
            'Expected the first package in the raw lock data to be an array.',
        );
        self::assertNull(
            $hydratedLockProperty->getValue($this->composerFallback),
            "Expected the hydrated lock data to be 'null' before restore.",
        );

        $this->composerFallback->restore();

        $hydratedLock = $hydratedLockProperty->getValue($this->composerFallback);

        self::assertIsArray(
            $hydratedLock,
            'Expected the hydrated lock data to be an array after restore.',
        );
        self::assertInstanceOf(
            PackageInterface::class,
            $hydratedLock['packages'][0],
            'Expected the first package in the hydrated lock data to be an instance of PackageInterface.',
        );

        $hydratedPackage = $hydratedLock['packages'][0];

        $this->composerFallback->restore();

        $restoredAgain = $hydratedLockProperty->getValue($this->composerFallback);

        self::assertIsArray(
            $restoredAgain,
            'Expected the restored again data to be an array.',
        );
        self::assertSame(
            $hydratedPackage,
            $restoredAgain['packages'][0],
            'Expected the restored again first package to match the previously hydrated package.',
        );
        self::assertSame(
            $rawLock,
            $lockProperty->getValue($this->composerFallback),
            'Expected the raw lock data to remain unchanged after restore.',
        );
    }

    public function testSaveWithDisabledOptionDoesNotReadComposerState(): void
    {
        $config = new Config(['fallback-composer' => false]);
        $composerFallback = new ComposerFallback($this->composer, $this->io, $config, $this->input);

        $this->composer
            ->expects(self::never())
            ->method('getConfig');
        $this->composer
            ->expects(self::never())
            ->method('getInstallationManager');

        self::assertSame(
            $composerFallback,
            $composerFallback->save(),
            'Expected the save method to return the composer fallback instance.',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_composer_fallback_test_', true);
        $this->config = new Config(['fallback-composer' => true]);
        $this->composer = $this->createMock(Composer::class);
        $this->io = $this->createMock(IOInterface::class);
        $this->input = $this->createMock(InputInterface::class);
        $this->fs = $this->createMock(Filesystem::class);
        $this->installer = $this
            ->getMockBuilder(Installer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['run', 'setRunScripts'])
            ->getMock();
        $this->sfs = new \Symfony\Component\Filesystem\Filesystem();
        $this->sfs->mkdir($this->cwd);

        chdir($this->cwd);

        $this->composerFallback = new ComposerFallback(
            $this->composer,
            $this->io,
            $this->config,
            $this->input,
            $this->fs,
            $this->installer,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);

        $this->sfs->remove($this->cwd);
        $this->config = null;
        $this->composer = null;
        $this->io = null;
        $this->input = null;
        $this->fs = null;
        $this->installer = null;
        $this->sfs = null;
        $this->composerFallback = null;
        $this->oldCwd = null;
        $this->cwd = null;
    }

    private function expectInstallerRun(int $result = 0, int $times = 1): void
    {
        $this->installer
            ->expects(self::exactly($times))
            ->method('setRunScripts')
            ->with(false)
            ->willReturnSelf();
        $this->installer
            ->expects(self::exactly($times))
            ->method('run')
            ->willReturn($result);
    }

    private function getInstallerProperty(string $name): mixed
    {
        return (new ReflectionClass(Installer::class))->getProperty($name)->getValue($this->installer);
    }

    private function setupNoLockEnvironment(int $restoreCount = 1): string
    {
        $vendorDir = $this->cwd . '/vendor';

        file_put_contents($this->cwd . '/composer.json', '{}');

        $installationManager = $this->createMock(InstallationManager::class);

        $this->composer
            ->expects(self::once())
            ->method('getInstallationManager')
            ->willReturn($installationManager);

        $config = $this->createMock(\Composer\Config::class);

        $config
            ->method('get')
            ->willReturnCallback(static fn($key, $default = null) => 'vendor-dir' === $key ? $vendorDir : $default);
        $this->composer
            ->method('getConfig')
            ->willReturn($config);
        $this->composer
            ->expects(self::never())
            ->method('getLocker');
        $this->io
            ->expects(self::exactly($restoreCount))
            ->method('write');

        return $vendorDir;
    }

    private function setupRestoreEnvironment(
        array $packages,
        callable $optionCallback,
        array $aliases = [],
        int $restoreCount = 1,
        array $configOptions = [],
        array $lockOptions = [],
    ): void {
        $composerFile = 'composer.json';
        $composerContent = '{}';
        $lockFile = 'composer.lock';
        $vendorDir = "{$this->cwd}/vendor/";

        file_put_contents(
            "{$this->cwd}/$composerFile",
            $composerContent,
        );
        file_put_contents(
            "{$this->cwd}/$lockFile",
            json_encode(
                array_replace([
                    'content-hash' => 'HASH_VALUE',
                    'packages' => $packages,
                    'packages-dev' => [],
                    'aliases' => $aliases,
                    'prefer-stable' => true,
                ], $lockOptions),
                JSON_THROW_ON_ERROR,
            ),
        );

        $this->input
            ->expects(self::any())
            ->method('getOption')
            ->willReturnCallback($optionCallback);
        $this->composer
            ->expects(self::never())
            ->method('getEventDispatcher');

        $repositoryManager = $this->createMock(RepositoryManager::class);

        $this->composer
            ->expects(self::any())
            ->method('getRepositoryManager')
            ->willReturn($repositoryManager);

        $installationManager = $this->createMock(InstallationManager::class);

        $this->composer
            ->expects(self::any())
            ->method('getInstallationManager')
            ->willReturn($installationManager);
        $this->io
            ->expects(self::exactly($restoreCount))
            ->method('write');

        $locker = LockerUtil::getLocker($this->io, $installationManager, $composerFile);

        $this->composer
            ->expects(self::atLeastOnce())
            ->method('getLocker')
            ->willReturn($locker);

        $config = $this->getMockBuilder(\Composer\Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();

        $this->composer
            ->expects(self::atLeastOnce())
            ->method('getConfig')
            ->willReturn($config);

        $config
            ->expects(self::atLeastOnce())
            ->method('get')
            ->willReturnCallback(
                fn($key, $default = null) => 'vendor-dir' === $key
                    ? $vendorDir
                    : ($configOptions[$key] ?? $default),
            );
    }
}
