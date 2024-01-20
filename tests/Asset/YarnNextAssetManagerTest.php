<?php

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
 * Yarn Next asset manager tests.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class YarnNextAssetManagerTest extends AssetManager
{
    public function actionForTestRunForInstallCommand($action)
    {
        $this->executor->addExpectedValues(0, '2.0.0');

        if ('update' === $action) {
            $this->executor->addExpectedValues(0, '2.0.0');
        }
    }

    
    protected function getManager()
    {
        return new YarnManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    
    protected function getValidName()
    {
        return 'yarn';
    }

    
    protected function getValidLockPackageName()
    {
        return 'yarn.lock';
    }

    
    protected function getValidVersionCommand()
    {
        return 'yarn --version';
    }

    
    protected function getValidInstallCommand()
    {
        return 'yarn install';
    }

    
    protected function getValidUpdateCommand()
    {
        return 'yarn up';
    }

    
    protected function actionForTestAddDependenciesForUpdateCommand()
    {
        $this->executor->addExpectedValues(0, '2.0.0');
        $this->executor->addExpectedValues(0, 'CHECK OUTPUT');
    }
}
