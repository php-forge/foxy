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

namespace Foxy\Asset;

/**
 * Pnpm Manager.
 *
 * @author Wilmer Arambula (terabytesfotw@gmail.com)
 */
final class BunManager extends AbstractAssetManager
{
    public function getName(): string
    {
        return 'bun';
    }

    public function getLockPackageName(): string
    {
        return 'bun.lockb';
    }

    public function isInstalled(): bool
    {
        return parent::isInstalled() && file_exists($this->getLockPackageName());
    }

    protected function getVersionCommand(): string
    {
        return $this->buildCommand('bun', 'version', '--version');
    }

    protected function getInstallCommand(): string
    {
        return $this->buildCommand('bun', 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        return $this->buildCommand('bun', 'update', 'update');
    }
}
