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

use Foxy\Asset\PnpmManager;

/**
 * Pnpm asset manager tests.
 *
 * @author Steffen Dietz <steffo.dietz@gmail.com>
 *
 * @internal
 */
final class PnpmAssetManagerTest extends AssetManager
{
    protected function getManager()
    {
        return new PnpmManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    
    protected function getValidName()
    {
        return 'pnpm';
    }

    
    protected function getValidLockPackageName()
    {
        return 'pnpm-lock.yaml';
    }

    
    protected function getValidVersionCommand()
    {
        return 'pnpm --version';
    }

    
    protected function getValidInstallCommand()
    {
        return 'pnpm install';
    }

    
    protected function getValidUpdateCommand()
    {
        return 'pnpm update';
    }
}
