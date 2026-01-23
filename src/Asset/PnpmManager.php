<?php

declare(strict_types=1);

namespace Foxy\Asset;

final class PnpmManager extends AbstractAssetManager
{
    public function getLockPackageName(): string
    {
        return 'pnpm-lock.yaml';
    }

    public function getName(): string
    {
        return 'pnpm';
    }

    public function isInstalled(): bool
    {
        return parent::isInstalled() && file_exists($this->getLockFilePath());
    }

    protected function getInstallCommand(): string
    {
        return $this->buildCommand('pnpm', 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        return $this->buildCommand('pnpm', 'update', 'update');
    }

    protected function getVersionCommand(): string
    {
        return $this->buildCommand('pnpm', 'version', '--version');
    }
}
