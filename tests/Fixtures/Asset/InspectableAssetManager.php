<?php

declare(strict_types=1);

namespace Foxy\Tests\Fixtures\Asset;

use Foxy\Asset\AbstractAssetManager;

final class InspectableAssetManager extends AbstractAssetManager
{
    /**
     * @var list<string>|null
     */
    private array|null $handledDependencies = null;

    public function buildCommandForTest(string $defaultBin, string $action, array|string $command): string
    {
        return $this->buildCommand($defaultBin, $action, $command);
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

    protected function actionWhenComposerDependenciesAreAlreadyInstalled(array $names): void
    {
        parent::actionWhenComposerDependenciesAreAlreadyInstalled($names);

        $this->handledDependencies = $names;
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
        return $this->buildCommand('inspectable', 'version', '--version');
    }
}
