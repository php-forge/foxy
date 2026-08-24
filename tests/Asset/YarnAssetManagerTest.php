<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Foxy\Asset\YarnManager;
use Foxy\Config\Config;

use function file_put_contents;

use const DIRECTORY_SEPARATOR;

final class YarnAssetManagerTest extends AssetManager
{
    public function actionForTestRunForInstallCommand($action): void
    {
        $this->executor->addExpectedValues(0, '1.0.0');

        if ('update' === $action) {
            $this->executor->addExpectedValues(0, '1.0.0');
            $this->executor->addExpectedValues(0, '1.0.0');
            $this->executor->addExpectedValues(0, 'CHECK OUTPUT');
        }
    }

    public function testClassicUpdateChecksInstalledDependencies(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', '{}');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'yarn.lock', '');
        $this->sfs->mkdir($this->cwd . DIRECTORY_SEPARATOR . 'node_modules');
        $this->config = new Config([], ['run-asset-manager' => true]);
        $this->executor->addExpectedValues(0, '1.0.0');
        $this->executor->addExpectedValues(0, 'CHECK OUTPUT');
        $this->executor->addExpectedValues(0, 'UPDATE OUTPUT');
        $this->manager = $this->getManager();

        self::assertSame(0, $this->manager->run());
        self::assertSame('yarn check --non-interactive', $this->executor->getExecutedCommand(1));
        self::assertSame('yarn upgrade --non-interactive', $this->executor->getExecutedCommand(2));
    }

    protected function actionForTestAddDependenciesForUpdateCommand(): void
    {
        $this->executor->addExpectedValues(0, '1.0.0');
        $this->executor->addExpectedValues(0, 'CHECK OUTPUT');
    }

    protected function getManager(): YarnManager
    {
        return new YarnManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getValidInstallCommand(): string
    {
        return 'yarn install --non-interactive';
    }

    protected function getValidLockPackageName(): string
    {
        return 'yarn.lock';
    }

    protected function getValidName(): string
    {
        return 'yarn';
    }

    protected function getValidUpdateCommand(): string
    {
        return 'yarn upgrade --non-interactive';
    }

    protected function getValidVersionCommand(): string
    {
        return 'yarn --version';
    }
}
