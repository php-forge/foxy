<?php

declare(strict_types=1);

namespace Foxy\Asset;

final class YarnManager extends AbstractAssetManager
{
    public function getLockPackageName(): string
    {
        return 'yarn.lock';
    }

    public function getName(): string
    {
        return 'yarn';
    }

    public function getVersionConstraint(): string
    {
        return '^4.18.0';
    }

    public function isInstalled(): bool
    {
        return file_exists($this->getPackageJsonPath())
            && file_exists($this->getLockFilePath())
            && (
                is_dir($this->getNodeModulesPath())
                || file_exists($this->getRootPackagePath('.pnp.cjs'))
            );
    }

    protected function getInstallCommand(): string
    {
        return $this->buildCommand('yarn', 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        return $this->buildCommand('yarn', 'update', 'up');
    }

    protected function getVersionCommand(): string
    {
        return $this->buildCommand('yarn', 'version', '--version');
    }
}
