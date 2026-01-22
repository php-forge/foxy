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

use Composer\Semver\VersionParser;

/**
 * Yarn Manager.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class YarnManager extends AbstractAssetManager
{
    public function getName(): string
    {
        return 'yarn';
    }

    public function getLockPackageName(): string
    {
        return 'yarn.lock';
    }

    public function isInstalled(): bool
    {
        return parent::isInstalled() && file_exists($this->getLockFilePath());
    }

    public function isValidForUpdate(): bool
    {
        if ($this->isYarnNext()) {
            return true;
        }

        $cmd = $this->buildCommand('yarn', 'check', $this->mergeInteractiveCommand(['check']));

        return 0 === $this->executor->execute($cmd);
    }

    protected function getVersionCommand(): string
    {
        return $this->buildCommand('yarn', 'version', '--version');
    }

    protected function getInstallCommand(): string
    {
        return $this->buildCommand('yarn', 'install', $this->mergeInteractiveCommand(['install']));
    }

    protected function getUpdateCommand(): string
    {
        $commandName = $this->isYarnNext() ? 'up' : 'upgrade';

        return $this->buildCommand('yarn', 'update', $this->mergeInteractiveCommand([$commandName]));
    }

    private function isYarnNext(): bool
    {
        $version = $this->getVersion();
        $parser = new VersionParser();
        $constraint = $parser->parseConstraints('>=2.0.0');

        return $version !== null ? $constraint->matches($parser->parseConstraints($version)) : false;
    }

    private function mergeInteractiveCommand(array $command): array
    {
        if (!$this->isYarnNext()) {
            $command[] = '--non-interactive';
        }

        return $command;
    }
}
