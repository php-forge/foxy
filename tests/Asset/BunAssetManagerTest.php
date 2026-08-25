<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\Util\Platform;
use Foxy\Asset\BunManager;
use Foxy\Config\Config;
use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunInSeparateProcess};

use function define;
use function defined;
use function file_put_contents;

final class BunAssetManagerTest extends AssetManager
{
    public function testIgnoresLegacyBinaryLockFile(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lockb', 'legacy');

        self::assertFalse($this->manager->hasLockFile());
    }

    public function testIgnoresLegacyBinaryLockFileInConfiguredRootDirectory(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'web';
        $this->sfs->mkdir($rootPackageDir);
        $this->config = new Config([], ['root-package-json-dir' => $rootPackageDir]);
        $this->manager = $this->getManager();

        file_put_contents($rootPackageDir . DIRECTORY_SEPARATOR . 'bun.lockb', 'legacy');

        self::assertFalse($this->manager->hasLockFile());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWindowsCommandsUseExecutableNameAndNormalizedCustomPath(): void
    {
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            define('PHP_WINDOWS_VERSION_BUILD', 1);
        }

        $this->executor->addExpectedValues(0, '1.4.0');
        $this->manager = $this->getManager();
        $this->manager->validate();

        self::assertSame('bun.exe --version', $this->executor->getLastCommand());

        $this->config = new Config(['run-asset-manager' => true, 'manager-bin' => 'C:/tools/bun.exe']);
        $this->executor->addExpectedValues(0, '1.4.0');
        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        self::assertSame(0, $this->getManager()->run());
        self::assertSame('C:\\tools\\bun.exe install', $this->executor->getLastCommand());
    }

    protected function getManager(): BunManager
    {
        return new BunManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getUnsupportedVersion(): string
    {
        return '1.3.9';
    }

    protected function getValidInstallCommand(): string
    {
        return Platform::isWindows() ? 'bun.exe install' : 'bun install';
    }

    protected function getValidLockPackageName(): string
    {
        return 'bun.lock';
    }

    protected function getValidName(): string
    {
        return 'bun';
    }

    protected function getValidUpdateCommand(): string
    {
        return Platform::isWindows() ? 'bun.exe update' : 'bun update';
    }

    protected function getValidVersion(): string
    {
        return '1.4.0';
    }

    protected function getValidVersionCommand(): string
    {
        return Platform::isWindows() ? 'bun.exe --version' : 'bun --version';
    }

    protected function getValidVersionConstraint(): string
    {
        return '^1.4.0';
    }
}
