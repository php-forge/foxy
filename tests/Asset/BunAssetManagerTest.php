<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\Util\Platform;
use Foxy\Asset\BunManager;

use function file_put_contents;

final class BunAssetManagerTest extends AssetManager
{
    public function testHasLegacyBinaryLockFile(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lockb', 'legacy');

        self::assertTrue($this->manager->hasLockFile());
    }

    protected function getManager(): BunManager
    {
        return new BunManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
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

    protected function getValidVersionCommand(): string
    {
        return Platform::isWindows() ? 'bun.exe --version' : 'bun --version';
    }
}
