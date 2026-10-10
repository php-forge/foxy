<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Exception;
use Foxy\Config\Config;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Fallback\FallbackInterface;
use Foxy\Json\JsonFile;
use Seld\JsonLint\ParsingException;
use Throwable;

use function is_dir;
use function is_string;
use function ltrim;
use function preg_match;
use function rtrim;

use const DIRECTORY_SEPARATOR;

/**
 * Provides the asset manifest handling shared by every asset manager: dependency merging, root package paths, lock
 * file detection, and fallback restoration after a failure.
 */
abstract class AbstractManifestAssetManager implements AssetManagerInterface
{
    final public const string NODE_MODULES_PATH = './node_modules';

    protected bool $updatable = true;

    public function __construct(
        protected IOInterface $io,
        protected Config $config,
        protected Filesystem $fs,
        protected FallbackInterface|null $fallback = null,
    ) {}

    /**
     * @throws Exception|ParsingException
     */
    public function addDependencies(RootPackageInterface $rootPackage, array $dependencies): AssetPackageInterface
    {
        try {
            $assetPackage = new AssetPackage(
                $rootPackage,
                new JsonFile($this->getPackageJsonPath(), null, $this->io),
            );

            $previousDependencies = $assetPackage->getInstalledDependencies();

            $assetPackage->removeUnusedDependencies($dependencies);

            $alreadyInstalledDependencies = $assetPackage->addNewDependencies($dependencies);

            if ($this->config->isEnabled('run-asset-manager')) {
                $this->actionWhenComposerDependenciesAreAlreadyInstalled($alreadyInstalledDependencies);
            }

            $this->actionWhenComposerDependenciesAreMerged($assetPackage, $previousDependencies);

            $this->io->write('<info>Merging Composer dependencies in the asset package</info>');

            return $assetPackage->write();
        } catch (Throwable $exception) {
            $this->restoreAfterFailure($exception);

            throw $exception;
        }
    }

    public function getPackageJsonPath(): string
    {
        return $this->getRootPackagePath($this->getPackageName());
    }

    public function getPackageName(): string
    {
        return 'package.json';
    }

    public function hasLockFile(): bool
    {
        return file_exists($this->getLockFilePath());
    }

    public function isInstalled(): bool
    {
        return is_dir($this->getNodeModulesPath()) && file_exists($this->getPackageJsonPath());
    }

    public function isUpdatable(): bool
    {
        return $this->updatable && $this->isInstalled();
    }

    public function setFallback(FallbackInterface $fallback): static
    {
        $this->fallback = $fallback;

        return $this;
    }

    public function setUpdatable(bool $updatable): static
    {
        $this->updatable = $updatable;

        return $this;
    }

    /**
     * @param list<string> $names the asset package name of composer dependencies.
     */
    protected function actionWhenComposerDependenciesAreAlreadyInstalled(array $names): void
    {
        // do nothing by default
    }

    /**
     * Runs after the Composer asset dependencies are merged and before the asset package is written.
     *
     * @param AssetPackageInterface $assetPackage The asset package with the merged Composer dependencies.
     * @param array<string, mixed> $previousDependencies The Composer asset dependencies declared before the merge.
     */
    protected function actionWhenComposerDependenciesAreMerged(
        AssetPackageInterface $assetPackage,
        array $previousDependencies,
    ): void {
        // do nothing by default
    }

    protected function getLockFilePath(): string
    {
        return $this->getRootPackagePath($this->getLockPackageName());
    }

    protected function getManagerWorkingDirectory(): string|null
    {
        if (null === $this->getConfiguredRootPackageDir()) {
            return null;
        }

        $rootPackageDir = $this->getRootPackageDir();

        if (!is_dir($rootPackageDir)) {
            throw new RuntimeException(
                Message::ASSET_ROOT_PACKAGE_DIR_MISSING->getMessage($rootPackageDir),
            );
        }

        return $rootPackageDir;
    }

    protected function getNodeModulesPath(): string
    {
        return $this->getRootPackagePath(ltrim(self::NODE_MODULES_PATH, './'));
    }

    protected function getRootPackageDir(): string
    {
        $rootPackageDir = $this->getConfiguredRootPackageDir();

        if (null === $rootPackageDir) {
            return $this->getCurrentDirectory();
        }

        $rootPackageDir = rtrim($rootPackageDir, '/\\');

        if ('' === $rootPackageDir) {
            $rootPackageDir = DIRECTORY_SEPARATOR;
        } elseif (1 === preg_match('/^[A-Za-z]:$/', $rootPackageDir)) {
            $rootPackageDir .= DIRECTORY_SEPARATOR;
        }

        if ($this->isAbsolutePath($rootPackageDir)) {
            return $rootPackageDir;
        }

        return rtrim($this->getCurrentDirectory(), '/\\') . DIRECTORY_SEPARATOR . $rootPackageDir;
    }

    protected function getRootPackagePath(string $path): string
    {
        return rtrim($this->getRootPackageDir(), '/\\') . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * Restore the asset manifest without hiding the manager failure.
     */
    final protected function restoreAfterFailure(Throwable $exception): void
    {
        if (null === $this->fallback) {
            return;
        }

        try {
            $this->fallback->restore();
        } catch (Throwable $fallbackException) {
            throw new RuntimeException(
                Message::ASSET_MANAGER_FALLBACK_RESTORE_FAILED->getMessage($fallbackException->getMessage()),
                previous: $exception,
            );
        }
    }

    /**
     * Returns the configured `root-package-json-dir` value, or `null` when it is unset or empty.
     */
    private function getConfiguredRootPackageDir(): string|null
    {
        $rootPackageDir = $this->config->get('root-package-json-dir');

        return is_string($rootPackageDir) && '' !== $rootPackageDir ? $rootPackageDir : null;
    }

    /**
     * Returns the current working directory of the PHP process.
     *
     * @throws RuntimeException if the current working directory cannot be determined.
     */
    private function getCurrentDirectory(): string
    {
        $currentDir = getcwd();

        if (false === $currentDir) {
            throw new RuntimeException(
                Message::CURRENT_WORKING_DIRECTORY_UNAVAILABLE->getMessage(),
            );
        }

        return $currentDir;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ('/' === $path[0] || '\\' === $path[0]) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
