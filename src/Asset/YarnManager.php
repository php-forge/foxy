<?php

declare(strict_types=1);

namespace Foxy\Asset;

final class YarnManager extends AbstractAssetManager
{
    private const array AUDIT_ENVIRONMENT = [
        'YARN_NPM_AUDIT_EXCLUDE_PACKAGES' => '__FOXY_AUDIT_NO_MATCH__',
        'YARN_NPM_AUDIT_IGNORE_ADVISORIES' => '__FOXY_AUDIT_NO_MATCH__',
    ];

    public function getLockPackageName(): string
    {
        return 'yarn.lock';
    }

    public function getName(): string
    {
        return 'yarn';
    }

    public function getVersionConstraint(): string
    {
        return '^4.18.0';
    }

    public function isInstalled(): bool
    {
        return file_exists($this->getPackageJsonPath())
            && file_exists($this->getLockFilePath())
            && (
                is_dir($this->getNodeModulesPath())
                || file_exists($this->getRootPackagePath('.pnp.cjs'))
            );
    }

    protected function getAuditCommand(bool $noDev): string
    {
        $command = ['npm', 'audit', '--all', '--recursive', '--json', '--no-deprecations', '--severity', 'info'];

        if ($noDev) {
            $command = [...$command, '--environment', 'production'];
        }

        return $this->buildUnconfiguredCommand('yarn', $command);
    }

    protected function getAuditEnvironment(): array
    {
        return self::AUDIT_ENVIRONMENT;
    }

    protected function getInstallCommand(): string
    {
        return $this->buildCommand('yarn', 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        return $this->buildCommand('yarn', 'update', 'up');
    }

    protected function getVersionCommand(): string
    {
        return $this->buildUnconfiguredCommand('yarn', '--version');
    }
}
