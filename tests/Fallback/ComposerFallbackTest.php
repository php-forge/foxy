<?php

declare(strict_types=1);

namespace Foxy\Tests\Fallback;

use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryManager;
use Composer\Util\Filesystem;
use Exception;
use Foxy\Config\Config;
use Foxy\Exception\RuntimeException;
use Foxy\Fallback\ComposerFallback;
use Foxy\Util\LockerUtil;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Input\InputInterface;

use function chdir;
use function file_get_contents;
use function file_put_contents;
use function json_decode;

use const DIRECTORY_SEPARATOR;

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

    public static function getIgnorePlatformReqData(): array
    {
        return [
            'ignore-platform-req is true' => ['ignore-platform-req', true],
            'ignore-platform-req is array' => ['ignore-platform-req', ['php', 'ext-json']],
        ];
    }

    public static function getIgnorePlatformReqsData(): array
    {
        return [
            'ignore-platform-reqs is true' => ['ignore-platform-reqs', true],
            'ignore-platform-reqs is array' => ['ignore-platform-reqs', ['php', 'ext-json']],
        ];
    }

    public static function getRestoreData(): array
    {
        return [[[]], [[['name' => 'foo/bar', 'version' => '1.0.0.0']]]];
    }

    public static function getSaveData(): array
    {
        return [[true], [false]];
    }

    /**
     * @throws Exception|JsonException
     */
    #[DataProvider('getRestoreData')]
    public function testRestore(array $packages): void
    {
        $this->setupRestoreEnvironment(
            $packages,
            static fn($option): bool|null => 'verbose' === $option ? false : null,
        );

        $this->fs->expects(self::never())->method('remove');
        $this->expectInstallerRun();

        $this->composerFallback->save();
        $this->composerFallback->restore();
    }

    /**
     * @throws Exception
     */
    public function testRestoreBeforeSaveDoesNothing(): void
    {
        $this->io->expects(self::never())->method('write');
        $this->composer->expects(self::never())->method('getLocker');
        $this->fs->expects(self::never())->method('remove');
        $this->installer->expects(self::never())->method('setRunScripts');
        $this->installer->expects(self::never())->method('run');

        $this->composerFallback->restore();
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

        $this->fs->expects(self::never())->method('remove');
        $this->expectInstallerRun();

        $this->composerFallback->save();
        $this->composerFallback->restore();

        $restoredLock = json_decode(
            file_get_contents($this->cwd . '/composer.lock'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($aliases, $restoredLock['aliases']);
    }

    /**
     * @throws Exception
     */
    public function testRestorePreservesVendorDirectoryThatExistedBeforeSavingWithoutLock(): void
    {
        $vendorDir = $this->cwd . '/vendor';
        $sentinel = $vendorDir . '/keep.txt';

        $this->sfs->mkdir($vendorDir);
        file_put_contents($sentinel, 'keep');

        $this->setupNoLockEnvironment();
        $this->composerFallback->save();

        file_put_contents($this->cwd . '/composer.lock', '{}');

        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->with('./composer.lock')
            ->willReturnCallback(function (string $path): bool {
                $this->sfs->remove($path);

                return true;
            });
        $this->installer->expects(self::never())->method('setRunScripts');
        $this->installer->expects(self::never())->method('run');

        $this->composerFallback->restore();

        self::assertFileExists($sentinel);
    }

    /**
     * @throws Exception
     */
    public function testRestoreRemovesOnlyStateCreatedAfterSavingWithoutLock(): void
    {
        $vendorDir = $this->setupNoLockEnvironment(2);

        $this->composerFallback->save();

        file_put_contents($this->cwd . '/composer.lock', '{}');
        $this->sfs->mkdir($vendorDir);

        $removed = [];

        $this->fs
            ->expects(self::exactly(2))
            ->method('remove')
            ->willReturnCallback(function (string $path) use (&$removed): bool {
                $removed[] = $path;
                $this->sfs->remove($path);

                return true;
            });
        $this->installer->expects(self::never())->method('setRunScripts');
        $this->installer->expects(self::never())->method('run');

        $this->composerFallback->restore();
        $this->composerFallback->restore();

        self::assertSame(['./composer.lock', $vendorDir], $removed);
        self::assertFileNotExists($this->cwd . '/composer.lock');
        self::assertFileNotExists($vendorDir);
    }

    /**
     * @throws Exception
     */
    public function testRestoreThrowsWhenCreatedLockCannotBeRemoved(): void
    {
        $this->setupNoLockEnvironment();
        $this->composerFallback->save();

        file_put_contents($this->cwd . '/composer.lock', '{}');

        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->with('./composer.lock')
            ->willReturn(false);
        $this->installer->expects(self::never())->method('setRunScripts');
        $this->installer->expects(self::never())->method('run');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to remove Composer fallback path "./composer.lock".');

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
        $this->installer->expects(self::never())->method('setRunScripts');
        $this->installer->expects(self::never())->method('run');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Unable to remove Composer fallback path "%s".', $vendorDir));

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
        $this->expectExceptionMessage('Unable to restore Composer dependencies, installer exited with code 7.');

        $this->composerFallback->restore();
    }

    /**
     * @throws Exception
     */
    public function testRestoreWithDisableOption(): void
    {
        $config = new Config(['fallback-composer' => false]);
        $composerFallback = new ComposerFallback($this->composer, $this->io, $config, $this->input);

        $this->io->expects(self::never())->method('write');

        $composerFallback->restore();
    }

    /**
     * @throws Exception|JsonException
     */
    #[DataProvider('getIgnorePlatformReqData')]
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
    }

    /**
     * @throws Exception|JsonException
     */
    #[DataProvider('getIgnorePlatformReqsData')]
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
    }

    /**
     * @throws JsonException
     */
    #[DataProvider('getSaveData')]
    public function testSave(bool $withLockFile): void
    {
        $rm = $this->createMock(RepositoryManager::class);

        $this->composer->expects(self::any())->method('getRepositoryManager')->willReturn($rm);

        $im = $this->createMock(InstallationManager::class);

        $this->composer->expects(self::any())->method('getInstallationManager')->willReturn($im);

        $config = $this->createMock(\Composer\Config::class);
        $config->method('get')->willReturn($this->cwd . '/vendor');
        $this->composer->method('getConfig')->willReturn($config);

        file_put_contents($this->cwd . '/composer.json', '{}');

        if ($withLockFile) {
            file_put_contents(
                "{$this->cwd}/composer.lock",
                json_encode(['content-hash' => 'HASH_VALUE'], JSON_THROW_ON_ERROR),
            );
        }

        self::assertInstanceOf(ComposerFallback::class, $this->composerFallback->save());
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

        $this->fs->expects(self::never())->method('remove');
        $this->expectInstallerRun(0, 2);

        $this->composerFallback->save();

        $reflection = new ReflectionClass($this->composerFallback);
        $lockProperty = $reflection->getProperty('lock');
        $hydratedLockProperty = $reflection->getProperty('hydratedLock');
        $rawLock = $lockProperty->getValue($this->composerFallback);

        self::assertIsArray($rawLock);
        self::assertIsArray($rawLock['packages'][0]);
        self::assertNull($hydratedLockProperty->getValue($this->composerFallback));

        $this->composerFallback->restore();

        $hydratedLock = $hydratedLockProperty->getValue($this->composerFallback);

        self::assertIsArray($hydratedLock);
        self::assertInstanceOf(PackageInterface::class, $hydratedLock['packages'][0]);

        $hydratedPackage = $hydratedLock['packages'][0];

        $this->composerFallback->restore();

        $restoredAgain = $hydratedLockProperty->getValue($this->composerFallback);

        self::assertIsArray($restoredAgain);
        self::assertSame($hydratedPackage, $restoredAgain['packages'][0]);
        self::assertSame($rawLock, $lockProperty->getValue($this->composerFallback));
    }

    public function testSaveWithDisabledOptionDoesNotReadComposerState(): void
    {
        $config = new Config(['fallback-composer' => false]);
        $composerFallback = new ComposerFallback($this->composer, $this->io, $config, $this->input);

        $this->composer->expects(self::never())->method('getConfig');
        $this->composer->expects(self::never())->method('getInstallationManager');

        self::assertSame($composerFallback, $composerFallback->save());
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

    private function setupNoLockEnvironment(int $restoreCount = 1): string
    {
        $vendorDir = $this->cwd . '/vendor';

        file_put_contents($this->cwd . '/composer.json', '{}');

        $installationManager = $this->createMock(InstallationManager::class);
        $this->composer->expects(self::once())->method('getInstallationManager')->willReturn($installationManager);

        $config = $this->createMock(\Composer\Config::class);
        $config
            ->method('get')
            ->willReturnCallback(static fn($key, $default = null) => 'vendor-dir' === $key ? $vendorDir : $default);
        $this->composer->method('getConfig')->willReturn($config);
        $this->composer->expects(self::never())->method('getLocker');
        $this->io->expects(self::exactly($restoreCount))->method('write');

        return $vendorDir;
    }

    private function setupRestoreEnvironment(
        array $packages,
        callable $optionCallback,
        array $aliases = [],
        int $restoreCount = 1,
    ): void {
        $composerFile = 'composer.json';
        $composerContent = '{}';
        $lockFile = 'composer.lock';
        $vendorDir = $this->cwd . '/vendor/';

        file_put_contents($this->cwd . '/' . $composerFile, $composerContent);
        file_put_contents(
            $this->cwd . '/' . $lockFile,
            json_encode(
                [
                    'content-hash' => 'HASH_VALUE',
                    'packages' => $packages,
                    'packages-dev' => [],
                    'aliases' => $aliases,
                    'prefer-stable' => true,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );

        $this->input
            ->expects(self::any())
            ->method('getOption')
            ->willReturnCallback($optionCallback);

        $eventDispatcher = $this->createMock(EventDispatcher::class);
        $eventDispatcher->expects(self::never())->method('setRunScripts');
        $this->composer->expects(self::never())->method('getEventDispatcher');

        $repositoryManager = $this->createMock(RepositoryManager::class);
        $this->composer->expects(self::any())->method('getRepositoryManager')->willReturn($repositoryManager);

        $installationManager = $this->createMock(InstallationManager::class);
        $this->composer->expects(self::any())->method('getInstallationManager')->willReturn($installationManager);

        $this->io->expects(self::exactly($restoreCount))->method('write');

        $locker = LockerUtil::getLocker($this->io, $installationManager, $composerFile);

        $this->composer->expects(self::atLeastOnce())->method('getLocker')->willReturn($locker);

        $config = $this->getMockBuilder(\Composer\Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();

        $this->composer->expects(self::atLeastOnce())->method('getConfig')->willReturn($config);

        $config
            ->expects(self::atLeastOnce())
            ->method('get')
            ->willReturnCallback(fn($key, $default = null) => 'vendor-dir' === $key ? $vendorDir : $default);
    }
}
