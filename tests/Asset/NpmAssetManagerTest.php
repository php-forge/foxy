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

use Foxy\Asset\NpmManager;

/**
 * NPM asset manager tests.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class NpmAssetManagerTest extends AssetManager
{
    protected function getManager()
    {
        return new NpmManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    
    protected function getValidName()
    {
        return 'npm';
    }

    
    protected function getValidLockPackageName()
    {
        return 'package-lock.json';
    }

    
    protected function getValidVersionCommand()
    {
        return 'npm --version';
    }

    
    protected function getValidInstallCommand()
    {
        return 'npm install';
    }

    
    protected function getValidUpdateCommand()
    {
        return 'npm update';
    }
}
