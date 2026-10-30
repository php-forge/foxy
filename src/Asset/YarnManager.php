<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Foxy\Audit\AuditParserInterface;
use Foxy\Audit\Parser\YarnAuditParser;

/**
 * Integrates Yarn with Foxy's install, update, Plug'n'Play, and audit workflows.
 */
final class YarnManager extends AbstractAssetManager
{
    private const array AUDIT_ENVIRONMENT = [
        'YARN_NPM_AUDIT_EXCLUDE_PACKAGES' => '__FOXY_AUDIT_NO_MATCH__',
        'YARN_NPM_AUDIT_IGNORE_ADVISORIES' => '__FOXY_AUDIT_NO_MATCH__',
    ];

    /**
     * Returns the parser for Yarn audit JSON output.
     */
    public function getAuditParser(): AuditParserInterface
    {
        return new YarnAuditParser();
    }

    /**
     * Returns Yarn's lock-file name.
     */
    public function getLockPackageName(): string
    {
        return 'yarn.lock';
    }

    /**
     * Returns the manager name used in Foxy configuration.
     */
    public function getName(): string
    {
        return 'yarn';
    }

    /**
     * Returns the Yarn versions supported by this integration.
     */
    public function getVersionConstraint(): string
    {
        return '^4.18.0';
    }

    /**
     * Returns whether the manifest and lock file exist with either `node_modules` or Plug'n'Play output.
     */
    public function isInstalled(): bool
    {
        return file_exists($this->getPackageJsonPath())
            && file_exists($this->getLockFilePath())
            && (
                is_dir($this->getNodeModulesPath())
                || file_exists($this->getRootPackagePath('.pnp.cjs'))
            );
    }

    /**
     * Returns the Yarn recursive audit command for all or production dependencies.
     */
    protected function getAuditCommand(bool $noDev): string
    {
        $command = ['npm', 'audit', '--all', '--recursive', '--json', '--no-deprecations', '--severity', 'info'];

        if ($noDev) {
            $command = [...$command, '--environment', 'production'];
        }

        return $this->buildUnconfiguredCommand($command);
    }

    /**
     * Returns environment overrides that prevent exclusions from Yarn audit results.
     */
    protected function getAuditEnvironment(): array
    {
        return self::AUDIT_ENVIRONMENT;
    }

    /**
     * Returns Yarn's dependency update command.
     */
    protected function getUpdateCommand(): string
    {
        return $this->buildCommand('update', 'up');
    }
}
