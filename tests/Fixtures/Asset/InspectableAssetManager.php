<?php

declare(strict_types=1);

namespace Foxy\Tests\Fixtures\Asset;

use Foxy\Asset\AbstractAssetManager;

final class InspectableAssetManager extends AbstractAssetManager
{
    /**
     * @var array<string, string>|null
     */
    private array|null $auditEnvironment = null;

    /**
     * @var list<string>|null
     */
    private array|null $handledDependencies = null;

    private bool|null $validatedNoDev = null;

    public function buildCommandForTest(string $defaultBin, string $action, array|string $command): string
    {
        return $this->buildCommand($defaultBin, $action, $command);
    }

    public function disableVersionConverterForTest(): void
    {
        $this->versionConverter = null;
    }

    public function getAuditCommandForTest(bool $noDev = false): string
    {
        return $this->getAuditCommand($noDev);
    }

    public function getAuditValidationForTest(): bool|null
    {
        return $this->validatedNoDev;
    }

    /**
     * @return list<string>|null
     */
    public function getHandledDependencies(): array|null
    {
        return $this->handledDependencies;
    }

    public function getLockFilePathForTest(): string
    {
        return $this->getLockFilePath();
    }

    public function getLockPackageName(): string
    {
        return 'inspectable.lock';
    }

    public function getName(): string
    {
        return 'inspectable';
    }

    public function getNodeModulesPathForTest(): string
    {
        return $this->getNodeModulesPath();
    }

    public function getRootPackageDirForTest(): string
    {
        return $this->getRootPackageDir();
    }

    public function getVersionConstraint(): string
    {
        return '*';
    }

    public function getVersionForTest(): string|null
    {
        return $this->getVersion();
    }

    /**
     * @param array<string, string> $environment
     */
    public function setAuditEnvironmentForTest(array $environment): void
    {
        $this->auditEnvironment = $environment;
    }

    protected function actionWhenComposerDependenciesAreAlreadyInstalled(array $names): void
    {
        parent::actionWhenComposerDependenciesAreAlreadyInstalled($names);

        $this->handledDependencies = $names;
    }

    protected function getAuditCommand(bool $noDev): string
    {
        return $this->buildUnconfiguredCommand('inspectable', $noDev ? ['audit', '--prod'] : ['audit']);
    }

    protected function getAuditEnvironment(): array
    {
        return [...parent::getAuditEnvironment(), ...($this->auditEnvironment ?? [])];
    }

    protected function getInstallCommand(): string
    {
        return $this->buildCommand('inspectable', 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        return $this->buildCommand('inspectable', 'update', 'update');
    }

    protected function getVersionCommand(): string
    {
        return $this->buildUnconfiguredCommand('inspectable', '--version');
    }

    protected function validateAuditConfiguration(bool $noDev): void
    {
        parent::validateAuditConfiguration($noDev);

        $this->validatedNoDev = $noDev;
    }
}
