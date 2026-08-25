<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\Package\RootPackageInterface;
use Composer\Util\ProcessExecutor;
use Foxy\Asset\NpmManager;
use Foxy\Config\Config;
use Foxy\Exception\RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function file_put_contents;
use function implode;

use const DIRECTORY_SEPARATOR;

final class NpmAssetManagerTest extends AssetManager
{
    #[DataProvider('workspaceLocksThatCannotBeEnumerated')]
    public function testAuditFailsClosedWhenWorkspaceGraphCannotBeEnumerated(string|null $manifest, string $lock): void
    {
        if (null !== $manifest) {
            file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', $manifest);
        }

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package-lock.json', $lock);
        $this->executor->addExpectedValues(0, $this->getValidVersion());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The npm workspace graph could not be enumerated from package-lock.json. '
            . 'Regenerate the lock file with a supported npm version.',
        );

        $this->getManager()->audit(false);
    }

    #[DataProvider('workspaceManifests')]
    public function testAuditForcesTheCompleteWorkspaceGraph(string $manifest, string $lock, array $workspacePaths): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', $manifest);
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package-lock.json', $lock);
        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(false);

        $workspaceSelectors = implode(
            ' ',
            array_map(
                static fn(string $path): string => ProcessExecutor::escape('--workspace=' . $path),
                $workspacePaths,
            ),
        );

        self::assertSame(
            'npm audit --json --package-lock-only --package-lock=true --audit-level=info --prefix=. --workspaces=true '
            . $workspaceSelectors
            . ' --include-workspace-root=true --include=dev --include=optional --include=peer',
            $this->executor->getExecutedCommand(1),
        );
    }

    public function testExistingDependencyCleanupIsSkippedWhenManagerExecutionIsDisabled(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'web';
        $this->sfs->mkdir($rootPackageDir);
        $this->config = new Config(
            [],
            ['root-package-json-dir' => $rootPackageDir, 'run-asset-manager' => false],
        );
        $this->manager = $this->getManager();

        file_put_contents(
            $rootPackageDir . DIRECTORY_SEPARATOR . 'package.json',
            '{"dependencies":{"@composer-asset/foo--bar":"file:../asset/foo/bar"}}',
        );

        $this->fs->expects(self::never())->method('remove');

        $rootPackage = $this->createMock(RootPackageInterface::class);
        $rootPackage->method('getLicense')->willReturn([]);

        $assetPackage = $this->manager->addDependencies(
            $rootPackage,
            [
                '@composer-asset/foo--bar' => $this->cwd . '/asset/foo/bar/package.json',
                '@composer-asset/new--dependency' => $this->cwd . '/asset/new/dependency/package.json',
            ],
        );

        self::assertArrayHasKey('@composer-asset/new--dependency', $assetPackage->getPackage()['dependencies']);
    }

    public function testExistingDependencyCleanupUsesConfiguredRootDirectory(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'web';
        $this->sfs->mkdir($rootPackageDir);
        $this->config = new Config(
            [],
            ['root-package-json-dir' => $rootPackageDir, 'run-asset-manager' => true],
        );
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
                        $output = '10.9.8';

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

    public static function workspaceLocksThatCannotBeEnumerated(): array
    {
        return [
            'legacy lock without package map' => [
                '{"workspaces":["packages/*"]}',
                '{"lockfileVersion":1}',
            ],
            'lock without workspace entries' => [
                '{"workspaces":["packages/*"]}',
                '{"packages":{"":{"workspaces":["packages/*"]}}}',
            ],
            'stale workspace declaration' => [
                '{"workspaces":["packages/*"]}',
                '{"packages":{"":{"workspaces":["other/*"]},"packages/a":{}}}',
            ],
            'manifest without locked workspaces' => [
                '{}',
                '{"packages":{"":{"workspaces":["packages/*"]},"packages/a":{}}}',
            ],
            'missing manifest with locked workspaces' => [
                null,
                '{"packages":{"":{"workspaces":["packages/*"]},"packages/a":{}}}',
            ],
            'malformed manifest workspace declaration' => [
                '{"workspaces":"packages/*"}',
                '{"packages":{"":{}}}',
            ],
            'malformed locked workspace declaration' => [
                '{}',
                '{"packages":{"":{"workspaces":{"packages":"packages/*"}}}}',
            ],
        ];
    }
    public static function workspaceManifests(): array
    {
        return [
            'workspace list' => [
                '{"workspaces":["packages/*"]}',
                '{"packages":{"":{"workspaces":["packages/*"]},"node_modules/a":{"link":true,"resolved":"packages/a"},"packages/a":{}}}',
                ['packages/a'],
            ],
            'workspace packages object' => [
                '{"workspaces":{"packages":["packages/*"]}}',
                '{"packages":{"":{"workspaces":{"packages":["packages/*"]}},"node_modules/a":{"link":true,"resolved":"packages/a"},"packages/a":{}}}',
                ['packages/a'],
            ],
            'dot-leading workspace path' => [
                '{"workspaces":["visible",".hidden"]}',
                '{"packages":{"":{"workspaces":["visible",".hidden"]},".hidden":{},"node_modules/hidden":{"link":true,"resolved":".hidden"},"node_modules/visible":{"link":true,"resolved":"visible"},"visible":{}}}',
                ['.hidden', 'visible'],
            ],
        ];
    }

    protected function getManager(): NpmManager
    {
        return new NpmManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getUnsupportedVersion(): string
    {
        return '10.9.7';
    }

    protected function getValidAuditCommand(bool $noDev): string
    {
        $command = 'npm audit --json --package-lock-only --package-lock=true --audit-level=info --prefix=.';

        return $command . ($noDev
            ? ' --omit=dev --include=optional --include=peer'
            : ' --include=dev --include=optional --include=peer');
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
        return '10.9.8';
    }

    protected function getValidVersionCommand(): string
    {
        return 'npm --version';
    }

    protected function getValidVersionConstraint(): string
    {
        return '>=10.9.8';
    }
}
