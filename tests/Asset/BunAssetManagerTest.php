<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\Util\Platform;
use Foxy\Asset\BunManager;

final class BunAssetManagerTest extends AssetManager
{
    protected function getManager(): BunManager
    {
        return new BunManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getValidInstallCommand(): string
    {
        return Platform::isWindows() ? 'bun.exe install -y' : 'bun install -y';
    }

    protected function getValidLockPackageName(): string
    {
        return 'yarn.lock';
    }

    protected function getValidName(): string
    {
        return 'bun';
    }

    protected function getValidUpdateCommand(): string
    {
        return Platform::isWindows() ? 'bun.exe update -y' : 'bun update -y';
    }

    protected function getValidVersionCommand(): string
    {
        return Platform::isWindows() ? 'bun.exe --version' : 'bun --version';
    }
}
