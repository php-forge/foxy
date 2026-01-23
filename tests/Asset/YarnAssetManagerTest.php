<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Foxy\Asset\YarnManager;

final class YarnAssetManagerTest extends AssetManager
{
    public function actionForTestRunForInstallCommand($action): void
    {
        $this->executor->addExpectedValues(0, '1.0.0');

        if ('update' === $action) {
            $this->executor->addExpectedValues(0, '1.0.0');
            $this->executor->addExpectedValues(0, '1.0.0');
            $this->executor->addExpectedValues(0, 'CHECK OUTPUT');
        }
    }

    protected function actionForTestAddDependenciesForUpdateCommand(): void
    {
        $this->executor->addExpectedValues(0, '1.0.0');
        $this->executor->addExpectedValues(0, 'CHECK OUTPUT');
    }

    protected function getManager(): YarnManager
    {
        return new YarnManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getValidInstallCommand(): string
    {
        return 'yarn install --non-interactive';
    }

    protected function getValidLockPackageName(): string
    {
        return 'yarn.lock';
    }

    protected function getValidName(): string
    {
        return 'yarn';
    }

    protected function getValidUpdateCommand(): string
    {
        return 'yarn upgrade --non-interactive';
    }

    protected function getValidVersionCommand(): string
    {
        return 'yarn --version';
    }
}
