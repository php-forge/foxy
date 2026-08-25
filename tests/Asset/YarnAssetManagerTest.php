<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Foxy\Asset\YarnManager;

use function file_put_contents;

use const DIRECTORY_SEPARATOR;

final class YarnAssetManagerTest extends AssetManager
{
    public function testIsInstalledRequiresLockFileWithPlugAndPlayState(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', '{}');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . '.pnp.cjs', '');

        self::assertFalse($this->manager->isInstalled());
    }

    public function testIsInstalledRequiresPackageFileWithPlugAndPlayState(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'yarn.lock', '');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . '.pnp.cjs', '');

        self::assertFalse($this->manager->isInstalled());
    }

    public function testIsInstalledWithPlugAndPlayState(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', '{}');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'yarn.lock', '');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . '.pnp.cjs', '');

        self::assertTrue($this->manager->isInstalled());
    }

    protected function getManager(): YarnManager
    {
        return new YarnManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getUnsupportedVersion(): string
    {
        return '4.17.1';
    }

    protected function getValidInstallCommand(): string
    {
        return 'yarn install';
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
        return 'yarn up';
    }

    protected function getValidVersion(): string
    {
        return '4.18.0';
    }

    protected function getValidVersionCommand(): string
    {
        return 'yarn --version';
    }

    protected function getValidVersionConstraint(): string
    {
        return '^4.18.0';
    }
}
