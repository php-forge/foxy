<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\Util\Platform;
use Foxy\Exception\RuntimeException;

use function array_diff;
use function array_is_list;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function substr;

final class DenoManager extends AbstractAssetManager
{
    public function getLockPackageName(): string
    {
        return 'deno.lock';
    }

    public function getName(): string
    {
        return 'deno';
    }

    public function getVersionConstraint(): string
    {
        return '^2.9.7';
    }

    public function isInstalled(): bool
    {
        return parent::isInstalled() && $this->hasLockFile();
    }

    protected function actionWhenComposerDependenciesAreMerged(
        AssetPackageInterface $assetPackage,
        array $previousDependencies,
    ): void {
        $members = [];

        foreach ($assetPackage->getInstalledDependencies() as $name => $dependency) {
            $member = $this->getWorkspaceMember($dependency);

            if (!$this->isNestedInRootPackageDir($member)) {
                throw new RuntimeException(
                    sprintf(
                        'The Composer asset "%s" must be located in a subdirectory of the root package directory to '
                        . 'be installed with deno.',
                        $name,
                    ),
                );
            }

            $members[] = $member;
        }

        $staleMembers = [];

        foreach ($previousDependencies as $dependency) {
            if (is_string($dependency) && str_starts_with($dependency, 'file:')) {
                $staleMembers[] = $this->getWorkspaceMember($dependency);
            }
        }

        if ([] === $members && [] === $staleMembers) {
            return;
        }

        $package = $assetPackage->getPackage();
        $workspaces = $package['workspaces'] ?? [];

        if (!$this->isListOfStrings($workspaces)) {
            throw new RuntimeException(
                sprintf(
                    'The "workspaces" field of "%s" must be a list of strings to install Composer assets with deno.',
                    $this->getPackageJsonPath(),
                ),
            );
        }

        $workspaces = [...array_diff($workspaces, $staleMembers, $members), ...$members];

        if ([] === $workspaces) {
            unset($package['workspaces']);
        } else {
            $package['workspaces'] = $workspaces;
        }

        $assetPackage->setPackage($package);
    }

    protected function getInstallCommand(): string
    {
        $command = Platform::isWindows() ? 'deno.exe' : 'deno';

        return $this->buildCommand($command, 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        $command = Platform::isWindows() ? 'deno.exe' : 'deno';

        return $this->buildCommand($command, 'update', ['update', '--lockfile-only', '--recursive'])
            . ' && '
            . $this->getInstallCommand();
    }

    protected function getVersionCommand(): string
    {
        $command = Platform::isWindows() ? 'deno.exe' : 'deno';

        return $this->buildUnconfiguredCommand($command, '--version');
    }

    protected function normalizeVersionOutput(string $output): string
    {
        return 1 === preg_match('/^deno (\S+)/', $output, $matches) ? $matches[1] : $output;
    }

    /**
     * Returns the normalized workspace member path of a `file:` dependency relative to the root package directory.
     */
    private function getWorkspaceMember(string $dependency): string
    {
        return $this->fs->normalizePath(substr($dependency, 5));
    }

    private function isListOfStrings(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }

    private function isNestedInRootPackageDir(string $member): bool
    {
        return '' !== $member
            && '..' !== $member
            && !str_starts_with($member, '../')
            && !str_starts_with($member, '/')
            && 1 !== preg_match('/^[A-Za-z]:/', $member);
    }
}
