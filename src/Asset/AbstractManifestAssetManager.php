<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Exception;
use Foxy\Config\Config;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Json\JsonFile;
use Seld\JsonLint\ParsingException;

use function is_dir;
use function is_string;
use function ltrim;
use function preg_match;
use function rtrim;

use const DIRECTORY_SEPARATOR;

/**
 * Provides the asset manifest handling shared by every asset manager: dependency merging, root package paths, and lock
 * file detection.
 */
abstract class AbstractManifestAssetManager implements AssetManagerInterface
{
    final public const string NODE_MODULES_PATH = './node_modules';

    /**
     * Whether an existing frontend installation may be updated.
     */
    protected bool $updatable = true;

    /**
     * Creates a manager with the services shared by asset manifest operations.
     *
     * @param IOInterface $io Composer output used for progress messages.
     * @param Config $config Foxy configuration.
     * @param Filesystem $fs Filesystem used to resolve and update manifest paths.
     */
    public function __construct(
        protected IOInterface $io,
        protected Config $config,
        protected Filesystem $fs,
    ) {}

    /**
     * Merges Composer asset dependencies into the frontend package manifest.
     *
     * @throws Exception|ParsingException
     */
    public function addDependencies(RootPackageInterface $rootPackage, array $dependencies): AssetPackageInterface
    {
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
    }

    /**
     * Returns the frontend package manifest path.
     */
    public function getPackageJsonPath(): string
    {
        return $this->getRootPackagePath($this->getPackageName());
    }

    /**
     * Returns the frontend package manifest filename.
     */
    public function getPackageName(): string
    {
        return 'package.json';
    }

    /**
     * Returns whether the manager lock file exists.
     */
    public function hasLockFile(): bool
    {
        return file_exists($this->getLockFilePath());
    }

    /**
     * Returns whether both the dependency directory and package manifest exist.
     */
    public function isInstalled(): bool
    {
        return is_dir($this->getNodeModulesPath()) && file_exists($this->getPackageJsonPath());
    }

    /**
     * Returns whether an existing frontend installation may be updated.
     */
    public function isUpdatable(): bool
    {
        return $this->updatable && $this->isInstalled();
    }

    /**
     * Enables or disables updates of an existing frontend installation.
     */
    public function setUpdatable(bool $updatable): static
    {
        $this->updatable = $updatable;

        return $this;
    }

    /**
     * Handles dependencies already present in the frontend installation.
     *
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

    /**
     * Returns the manager lock-file path under the root package directory.
     */
    protected function getLockFilePath(): string
    {
        return $this->getRootPackagePath($this->getLockPackageName());
    }

    /**
     * Returns the configuration scoped to this manager, so `manager-*` map entries resolve to its own value.
     *
     * The view is built on each call because {@see getName()} must not run from a constructor.
     */
    protected function getManagerConfig(): Config
    {
        return $this->config->forManager($this->getName());
    }

    /**
     * Returns the configured manager working directory, or `null` to use the process default.
     */
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

    /**
     * Returns the `node_modules` path under the root package directory.
     */
    protected function getNodeModulesPath(): string
    {
        return $this->getRootPackagePath(ltrim(self::NODE_MODULES_PATH, './'));
    }

    /**
     * Returns the resolved root package directory.
     */
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

    /**
     * Resolves a path under the root package directory.
     */
    protected function getRootPackagePath(string $path): string
    {
        return rtrim($this->getRootPackageDir(), '/\\') . DIRECTORY_SEPARATOR . $path;
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
