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
    protected function getManager(): NpmManager
    {
        return new NpmManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getValidName(): string
    {
        return 'npm';
    }

    protected function getValidLockPackageName(): string
    {
        return 'package-lock.json';
    }

    protected function getValidVersionCommand(): string
    {
        return 'npm --version';
    }

    protected function getValidInstallCommand(): string
    {
        return 'npm install';
    }

    protected function getValidUpdateCommand(): string
    {
        return 'npm update';
    }
}
