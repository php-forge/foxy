<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\Package\RootPackageInterface;
use Composer\Util\ProcessExecutor;
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

    public function testRunAppliesConfiguredTimeoutDuringExecution(): void
    {
        $originalTimeout = ProcessExecutor::getTimeout();
        $configuredTimeout = 900;
        $observedTimeout = null;
        $executor = $this->createMock(ProcessExecutor::class);
        $executor
            ->expects(self::exactly(2))
            ->method('execute')
            ->willReturnCallback(
                static function (mixed $command, mixed &$output = null) use (&$observedTimeout): int {
                    if ('npm --version' === $command) {
                        $output = '12.0.2';

                        return 0;
                    }

                    $observedTimeout = ProcessExecutor::getTimeout();

                    return 0;
                },
            );
        $this->config = new Config(['run-asset-manager' => true, 'manager-timeout' => $configuredTimeout]);

        try {
            ProcessExecutor::setTimeout(42);

            $manager = new NpmManager($this->io, $this->config, $executor, $this->fs, $this->fallback);

            self::assertSame(0, $manager->run());
            self::assertSame($configuredTimeout, $observedTimeout);
            self::assertSame(42, ProcessExecutor::getTimeout());
        } finally {
            ProcessExecutor::setTimeout($originalTimeout);
        }
    }

    protected function getManager(): NpmManager
    {
        return new NpmManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getUnsupportedVersion(): string
    {
        return '12.0.1';
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

    protected function getValidVersion(): string
    {
        return '12.0.2';
    }

    protected function getValidVersionCommand(): string
    {
        return 'npm --version';
    }

    protected function getValidVersionConstraint(): string
    {
        return '^12.0.2';
    }
}
