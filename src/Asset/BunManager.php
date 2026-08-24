<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\Util\Platform;

final class BunManager extends AbstractAssetManager
{
    public function getLockPackageName(): string
    {
        return 'bun.lock';
    }

    public function getName(): string
    {
        return 'bun';
    }

    public function hasLockFile(): bool
    {
        return parent::hasLockFile()
            || file_exists($this->getRootPackagePath('bun.lockb'));
    }

    public function isInstalled(): bool
    {
        return parent::isInstalled() && $this->hasLockFile();
    }

    protected function getInstallCommand(): string
    {
        $command = Platform::isWindows() ? 'bun.exe' : 'bun';

        return $this->buildCommand($command, 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        $command = Platform::isWindows() ? 'bun.exe' : 'bun';

        return $this->buildCommand($command, 'update', 'update');
    }

    protected function getVersionCommand(): string
    {
        $command = Platform::isWindows() ? 'bun.exe' : 'bun';

        return $this->buildCommand($command, 'version', '--version');
    }
}
