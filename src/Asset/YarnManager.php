<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\Semver\VersionParser;

final class YarnManager extends AbstractAssetManager
{
    public function getLockPackageName(): string
    {
        return 'yarn.lock';
    }

    public function getName(): string
    {
        return 'yarn';
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

    protected function getInstallCommand(): string
    {
        return $this->buildCommand('yarn', 'install', $this->mergeInteractiveCommand(['install']));
    }

    protected function getUpdateCommand(): string
    {
        $commandName = $this->isYarnNext() ? 'up' : 'upgrade';

        return $this->buildCommand('yarn', 'update', $this->mergeInteractiveCommand([$commandName]));
    }

    protected function getVersionCommand(): string
    {
        return $this->buildCommand('yarn', 'version', '--version');
    }

    private function isYarnNext(): bool
    {
        $version = $this->getVersion();
        $parser = new VersionParser();
        $constraint = $parser->parseConstraints('>=2.0.0');

        return $version !== null && $constraint->matches($parser->parseConstraints($version));
    }

    private function mergeInteractiveCommand(array $command): array
    {
        if (!$this->isYarnNext()) {
            $command[] = '--non-interactive';
        }

        return $command;
    }
}
