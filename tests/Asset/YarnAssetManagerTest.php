<?php

declare(strict_types=1);

/*
 * This file is part of the Foxy package.
 *
 * (c) François Pluchino <francois.pluchino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Foxy\Tests\Asset;

use Foxy\Asset\YarnManager;

/**
 * Yarn asset manager tests.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
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

    protected function getManager(): YarnManager
    {
        return new YarnManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getValidName(): string
    {
        return 'yarn';
    }

    protected function getValidLockPackageName(): string
    {
        return 'yarn.lock';
    }

    protected function getValidVersionCommand(): string
    {
        return 'yarn --version';
    }

    protected function getValidInstallCommand(): string
    {
        return 'yarn install --non-interactive';
    }

    protected function getValidUpdateCommand(): string
    {
        return 'yarn upgrade --non-interactive';
    }

    protected function actionForTestAddDependenciesForUpdateCommand(): void
    {
        $this->executor->addExpectedValues(0, '1.0.0');
        $this->executor->addExpectedValues(0, 'CHECK OUTPUT');
    }
}
