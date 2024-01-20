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
    protected function getManager(): PnpmManager
    {
        return new PnpmManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getValidName(): string
    {
        return 'pnpm';
    }

    protected function getValidLockPackageName(): string
    {
        return 'pnpm-lock.yaml';
    }

    protected function getValidVersionCommand(): string
    {
        return 'pnpm --version';
    }

    protected function getValidInstallCommand(): string
    {
        return 'pnpm install';
    }

    protected function getValidUpdateCommand(): string
    {
        return 'pnpm update';
    }
}
