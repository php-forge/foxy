<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\Util\Platform;

final class BunManager extends AbstractAssetManager
{
    public function getLockPackageName(): string
    {
        return 'yarn.lock';
    }

    public function getName(): string
    {
        return 'bun';
    }

    public function isInstalled(): bool
    {
        return parent::isInstalled() && file_exists($this->getLockFilePath());
    }

    protected function getInstallCommand(): string
    {
        $command = Platform::isWindows() ? 'bun.exe' : 'bun';

        return $this->buildCommand($command, 'install', 'install -y');
    }

    protected function getUpdateCommand(): string
    {
        $command = Platform::isWindows() ? 'bun.exe' : 'bun';

        return $this->buildCommand($command, 'update', 'update -y');
    }

    protected function getVersionCommand(): string
    {
        $command = Platform::isWindows() ? 'bun.exe' : 'bun';

        return $this->buildCommand($command, 'version', '--version');
    }
}
