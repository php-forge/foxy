<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Foxy\Exception\{Message, RuntimeException};

use function array_is_list;
use function array_keys;
use function is_array;
use function is_string;
use function str_contains;
use function str_replace;
use function trim;

final class NpmManager extends AbstractAuditableAssetManager
{
    public function getLockPackageName(): string
    {
        return 'package-lock.json';
    }

    public function getName(): string
    {
        return 'npm';
    }

    public function getVersionConstraint(): string
    {
        return '>=10.9.8';
    }

    protected function actionWhenComposerDependenciesAreAlreadyInstalled(array $names): void
    {
        foreach ($names as $name) {
            $this->fs->remove($this->getNodeModulesPath() . '/' . $name);
        }
    }

    protected function getAuditCommand(bool $noDev): string
    {
        $command = [
            'audit',
            '--json',
            '--package-lock-only',
            '--package-lock=true',
            '--audit-level=info',
            '--prefix=.',
        ];

        $workspaceSelectors = $this->getWorkspaceSelectors();

        if ([] !== $workspaceSelectors) {
            $command = [
                ...$command,
                '--workspaces=true',
                ...$workspaceSelectors,
                '--include-workspace-root=true',
            ];
        }

        $command = [...$command, $noDev ? '--omit=dev' : '--include=dev', '--include=optional', '--include=peer'];

        return $this->buildUnconfiguredCommand('npm', $command);
    }

    protected function getInstallCommand(): string
    {
        return $this->buildCommand('npm', 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        return $this->buildCommand('npm', 'update', 'update');
    }

    protected function getVersionCommand(): string
    {
        return $this->buildUnconfiguredCommand('npm', '--version');
    }

    /**
     * @return list<string>
     */
    private function getWorkspacePatterns(mixed $workspaces): array
    {
        if (null === $workspaces) {
            return [];
        }

        if (!is_array($workspaces)) {
            throw $this->workspaceEnumerationFailure();
        }

        if (array_is_list($workspaces)) {
            $patterns = $workspaces;
        } else {
            $patterns = $workspaces['packages'] ?? null;

            if (!is_array($patterns) || !array_is_list($patterns)) {
                throw $this->workspaceEnumerationFailure();
            }
        }

        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || '' === trim($pattern)) {
                throw $this->workspaceEnumerationFailure();
            }
        }

        return $patterns;
    }

    /**
     * @return list<string>
     */
    private function getWorkspaceSelectors(): array
    {
        $packageJson = new JsonFile($this->getPackageJsonPath(), null, $this->io);

        $workspacePatterns = $packageJson->exists()
            ? $this->getWorkspacePatterns($packageJson->read()['workspaces'] ?? null)
            : [];

        $lockFile = new JsonFile($this->getLockFilePath(), null, $this->io);

        $packages = $lockFile->read()['packages'] ?? null;

        $rootPackage = is_array($packages) ? ($packages[''] ?? null) : null;
        $lockedWorkspacePatterns = is_array($rootPackage)
            ? $this->getWorkspacePatterns($rootPackage['workspaces'] ?? null)
            : [];

        if ([] === $workspacePatterns && [] === $lockedWorkspacePatterns) {
            return [];
        }

        if ($workspacePatterns !== $lockedWorkspacePatterns) {
            throw $this->workspaceEnumerationFailure();
        }

        $selectors = [];

        foreach (array_keys($packages) as $path) {
            $path = (string) $path;

            if ('' === $path || $this->isNodeModulesPath($path)) {
                continue;
            }

            $selectors[] = ProcessExecutor::escape('--workspace=' . $path);
        }

        if ([] === $selectors) {
            throw $this->workspaceEnumerationFailure();
        }

        return $selectors;
    }

    private function isNodeModulesPath(string $path): bool
    {
        $path = '/' . str_replace('\\', '/', $path) . '/';

        return str_contains($path, '/node_modules/');
    }

    private function workspaceEnumerationFailure(): RuntimeException
    {
        return new RuntimeException(
            Message::ASSET_NPM_WORKSPACE_GRAPH_UNENUMERABLE->getMessage(),
        );
    }
}
