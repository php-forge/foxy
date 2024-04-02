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

use Foxy\Asset\BunManager;

/**
 * Pnpm asset manager tests.
 *
 * @author Steffen Dietz <steffo.dietz@gmail.com>
 *
 * @internal
 */
final class BunAssetManagerTest extends AssetManager
{
    protected function getManager(): BunManager
    {
        return new BunManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getValidName(): string
    {
        return 'bun';
    }

    protected function getValidLockPackageName(): string
    {
        return 'bun.lockb';
    }

    protected function getValidVersionCommand(): string
    {
        return 'bun --version';
    }

    protected function getValidInstallCommand(): string
    {
        return 'bun install';
    }

    protected function getValidUpdateCommand(): string
    {
        return 'bun update';
    }
}
