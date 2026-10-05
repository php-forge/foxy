<?php

declare(strict_types=1);

namespace Foxy\Tests\Fixtures\Asset;

use Foxy\Asset\{AbstractAuditableAssetManager, AssetPackageInterface};

final class InspectableAssetManager extends AbstractAuditableAssetManager
{
    /**
     * @var array<string, string>|null
     */
    private array|null $auditEnvironment = null;

    /**
     * @var list<string>|null
     */
    private array|null $handledDependencies = null;

    private array|null $mergedPackage = null;

    private string|null $normalizedVersionOutput = null;

    /**
     * @var array<string, mixed>|null
     */
    private array|null $previousDependencies = null;

    private bool|null $validatedNoDev = null;

    private string|null $versionOutput = null;

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

    public function getMergedPackageForTest(): array|null
    {
        return $this->mergedPackage;
    }

    public function getName(): string
    {
        return 'inspectable';
    }

    public function getNodeModulesPathForTest(): string
    {
        return $this->getNodeModulesPath();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPreviousDependenciesForTest(): array|null
    {
        return $this->previousDependencies;
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

    public function getVersionOutputForTest(): string|null
    {
        return $this->versionOutput;
    }

    /**
     * @param array<string, string> $environment
     */
    public function setAuditEnvironmentForTest(array $environment): void
    {
        $this->auditEnvironment = $environment;
    }

    public function setNormalizedVersionOutputForTest(string $output): void
    {
        $this->normalizedVersionOutput = $output;
    }

    protected function actionWhenComposerDependenciesAreAlreadyInstalled(array $names): void
    {
        parent::actionWhenComposerDependenciesAreAlreadyInstalled($names);

        $this->handledDependencies = $names;
    }

    protected function actionWhenComposerDependenciesAreMerged(
        AssetPackageInterface $assetPackage,
        array $previousDependencies,
    ): void {
        parent::actionWhenComposerDependenciesAreMerged($assetPackage, $previousDependencies);

        $this->mergedPackage = $assetPackage->getPackage();
        $this->previousDependencies = $previousDependencies;
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

    protected function normalizeVersionOutput(string $output): string
    {
        $this->versionOutput = $output;

        return $this->normalizedVersionOutput ?? parent::normalizeVersionOutput($output);
    }

    protected function validateAuditConfiguration(bool $noDev): void
    {
        parent::validateAuditConfiguration($noDev);

        $this->validatedNoDev = $noDev;
    }
}
