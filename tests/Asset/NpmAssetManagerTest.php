<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\Package\RootPackageInterface;
use Foxy\Asset\NpmManager;
use Foxy\Config\Config;

use function file_put_contents;

use const DIRECTORY_SEPARATOR;

final class NpmAssetManagerTest extends AssetManager
{
    public function testExistingDependencyCleanupUsesConfiguredRootDirectory(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'web';
        $this->sfs->mkdir($rootPackageDir);
        $this->config = new Config([], ['root-package-json-dir' => $rootPackageDir]);
        $this->manager = $this->getManager();

        file_put_contents(
            $rootPackageDir . DIRECTORY_SEPARATOR . 'package.json',
            '{"dependencies":{"@composer-asset/foo--bar":"file:../asset/foo/bar"}}',
        );

        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->with($rootPackageDir . DIRECTORY_SEPARATOR . 'node_modules/@composer-asset/foo--bar');

        $rootPackage = $this->createMock(RootPackageInterface::class);
        $rootPackage->method('getLicense')->willReturn([]);

        $this->manager->addDependencies(
            $rootPackage,
            ['@composer-asset/foo--bar' => $this->cwd . '/asset/foo/bar/package.json'],
        );
    }

    protected function getManager(): NpmManager
    {
        return new NpmManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getValidInstallCommand(): string
    {
        return 'npm install';
    }

    protected function getValidLockPackageName(): string
    {
        return 'package-lock.json';
    }

    protected function getValidName(): string
    {
        return 'npm';
    }

    protected function getValidUpdateCommand(): string
    {
        return 'npm update';
    }

    protected function getValidVersionCommand(): string
    {
        return 'npm --version';
    }
}
