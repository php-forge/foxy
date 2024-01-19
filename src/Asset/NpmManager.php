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
 * NPM Manager.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class NpmManager extends AbstractAssetManager
{
    public function getName(): string
    {
        return 'npm';
    }

    public function getLockPackageName(): string
    {
        return 'package-lock.json';
    }

    protected function getVersionCommand(): string
    {
        return $this->buildCommand('npm', 'version', '--version');
    }

    protected function getInstallCommand(): string
    {
        return $this->buildCommand('npm', 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        return $this->buildCommand('npm', 'update', 'update');
    }

    protected function actionWhenComposerDependenciesAreAlreadyInstalled(array $names): void
    {
        foreach ($names as $name) {
            $this->fs->remove(self::NODE_MODULES_PATH . '/' . $name);
        }
    }
}
