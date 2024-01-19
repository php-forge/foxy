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
 * @author Steffen Dietz <steffo.dietz@gmail.com>
 */
final class PnpmManager extends AbstractAssetManager
{
    public function getName(): string
    {
        return 'pnpm';
    }

    public function getLockPackageName(): string
    {
        return 'pnpm-lock.yaml';
    }

    public function isInstalled(): bool
    {
        return parent::isInstalled() && file_exists($this->getLockPackageName());
    }

    protected function getVersionCommand(): string
    {
        return $this->buildCommand('pnpm', 'version', '--version');
    }

    protected function getInstallCommand(): string
    {
        return $this->buildCommand('pnpm', 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        return $this->buildCommand('pnpm', 'update', 'update');
    }
}
