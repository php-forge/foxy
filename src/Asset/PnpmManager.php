<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\Util\ProcessExecutor;
use Foxy\Audit\AuditParserInterface;
use Foxy\Audit\Parser\PnpmAuditParser;

/**
 * Integrates pnpm with Foxy's install, update, and audit workflows.
 */
final class PnpmManager extends AbstractAssetManager
{
    /**
     * Returns the parser for pnpm audit JSON output.
     */
    public function getAuditParser(): AuditParserInterface
    {
        return new PnpmAuditParser();
    }

    /**
     * Returns pnpm's lock-file name.
     */
    public function getLockPackageName(): string
    {
        return 'pnpm-lock.yaml';
    }

    /**
     * Returns the manager name used in Foxy configuration.
     */
    public function getName(): string
    {
        return 'pnpm';
    }

    /**
     * Returns the pnpm versions supported by this integration.
     */
    public function getVersionConstraint(): string
    {
        return '^11.23.0';
    }

    /**
     * Returns whether the package manifest, dependency directory, and pnpm lock file exist.
     */
    public function isInstalled(): bool
    {
        return parent::isInstalled() && file_exists($this->getLockFilePath());
    }

    /**
     * Returns the pnpm audit command for all or production dependencies.
     */
    protected function getAuditCommand(bool $noDev): string
    {
        $command = [
            'audit',
            '--json',
            '--audit-level=info',
            '--lockfile-dir=.',
            '--ignore-pnpmfile',
            '--only=null',
            ...($noDev ? ['--prod'] : ['--prod=false', '--dev=false']),
            '--optional=true',
            '--ignore-registry-errors=false',
            '--ignore-unfixable=false',
            ProcessExecutor::escape('--config.auditConfig={ignoreGhsas:[]}'),
        ];

        return $this->buildUnconfiguredCommand($command);
    }
}
