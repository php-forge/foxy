<?php

declare(strict_types=1);

namespace Foxy\Asset;

final class NpmManager extends AbstractAssetManager
{
    public function getLockPackageName(): string
    {
        return 'package-lock.json';
    }

    public function getName(): string
    {
        return 'npm';
    }

    public function getVersionConstraint(): string
    {
        return '^12.0.2';
    }

    protected function actionWhenComposerDependenciesAreAlreadyInstalled(array $names): void
    {
        foreach ($names as $name) {
            $this->fs->remove($this->getNodeModulesPath() . '/' . $name);
        }
    }

    protected function getInstallCommand(): string
    {
        return $this->buildCommand('npm', 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        return $this->buildCommand('npm', 'update', 'update');
    }

    protected function getVersionCommand(): string
    {
        return $this->buildCommand('npm', 'version', '--version');
    }
}
