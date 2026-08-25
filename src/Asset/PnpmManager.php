<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\Util\ProcessExecutor;

final class PnpmManager extends AbstractAssetManager
{
    public function getLockPackageName(): string
    {
        return 'pnpm-lock.yaml';
    }

    public function getName(): string
    {
        return 'pnpm';
    }

    public function getVersionConstraint(): string
    {
        return '^11.23.0';
    }

    public function isInstalled(): bool
    {
        return parent::isInstalled() && file_exists($this->getLockFilePath());
    }

    protected function getAuditCommand(bool $noDev): string
    {
        $command = [
            'audit',
            '--json',
            '--audit-level=info',
            '--lockfile-dir=.',
            '--ignore-pnpmfile',
            '--only=null',
        ];

        if ($noDev) {
            $command = [...$command, '--prod', '--optional=true'];
        } else {
            $command = [...$command, '--prod=false', '--dev=false', '--optional=true'];
        }

        $command = [
            ...$command,
            '--ignore-registry-errors=false',
            '--ignore-unfixable=false',
            ProcessExecutor::escape('--config.auditConfig={ignoreGhsas:[]}'),
        ];

        return $this->buildUnconfiguredCommand('pnpm', $command);
    }

    protected function getInstallCommand(): string
    {
        return $this->buildCommand('pnpm', 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        return $this->buildCommand('pnpm', 'update', 'update');
    }

    protected function getVersionCommand(): string
    {
        return $this->buildUnconfiguredCommand('pnpm', '--version');
    }
}
