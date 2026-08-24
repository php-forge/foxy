<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\Util\Platform;

use function rtrim;

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
            || file_exists(rtrim($this->getRootPackageDir(), '/\\') . DIRECTORY_SEPARATOR . 'bun.lockb');
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
