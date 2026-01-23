<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Foxy\Asset\NpmManager;

final class NpmAssetManagerTest extends AssetManager
{
    protected function getManager(): NpmManager
    {
        return new NpmManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getValidInstallCommand(): string
    {
        return 'npm install';
    }

    protected function getValidLockPackageName(): string
    {
        return 'package-lock.json';
    }

    protected function getValidName(): string
    {
        return 'npm';
    }

    protected function getValidUpdateCommand(): string
    {
        return 'npm update';
    }

    protected function getValidVersionCommand(): string
    {
        return 'npm --version';
    }
}
