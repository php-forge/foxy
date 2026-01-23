<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Foxy\Asset\PnpmManager;

final class PnpmAssetManagerTest extends AssetManager
{
    protected function getManager(): PnpmManager
    {
        return new PnpmManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getValidInstallCommand(): string
    {
        return 'pnpm install';
    }

    protected function getValidLockPackageName(): string
    {
        return 'pnpm-lock.yaml';
    }

    protected function getValidName(): string
    {
        return 'pnpm';
    }

    protected function getValidUpdateCommand(): string
    {
        return 'pnpm update';
    }

    protected function getValidVersionCommand(): string
    {
        return 'pnpm --version';
    }
}
