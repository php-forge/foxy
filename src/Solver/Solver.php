<?php

declare(strict_types=1);

namespace Foxy\Solver;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Exception;
use Foxy\Asset\AssetManagerInterface;
use Foxy\Config\Config;
use Foxy\Event\{GetAssetsEvent, PostSolveEvent, PreSolveEvent};
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Fallback\FallbackInterface;
use Foxy\FoxyEvents;
use Foxy\Util\AssetUtil;
use Throwable;

use function array_diff;
use function array_unshift;
use function basename;
use function dirname;
use function implode;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function realpath;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function trim;

final readonly class Solver implements SolverInterface
{
    private const string MANAGED_MARKER = '.foxy-managed';

    /**
     * @param AssetManagerInterface $assetManager The asset manager instance.
     * @param Config $config The config instance.
     * @param FallbackInterface|null $composerFallback The composer fallback instance.
     */
    public function __construct(
        private AssetManagerInterface $assetManager,
        private Config $config,
        private Filesystem $fs,
        private FallbackInterface|null $composerFallback = null,
    ) {}

    public function setUpdatable($updatable): self
    {
        $this->assetManager->setUpdatable($updatable);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function solve(Composer $composer, IOInterface $io): void
    {
        if (!$this->config->isEnabled('enabled')) {
            return;
        }

        $dispatcher = $composer->getEventDispatcher();
        $packages = $composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();

        try {
            $vendorDir = $composer->getConfig()->get('vendor-dir');
            $vendorDir = '' !== $vendorDir ? $vendorDir : 'vendor';
            $configuredAssetDir = $this->config->get(
                'composer-asset-dir',
                $vendorDir . '/php-forge/composer-asset/',
            );

            if (!is_string($configuredAssetDir)) {
                throw new RuntimeException(
                    Message::SOLVER_ASSET_DIR_NOT_STRING->getMessage(),
                );
            }

            $assetDir = $this->validateAssetDirectory($configuredAssetDir, $vendorDir);
            $dispatcher->dispatch(FoxyEvents::PRE_SOLVE, new PreSolveEvent($assetDir, $packages));
            $this->prepareAssetDirectory($assetDir, $vendorDir);
            $assets = $this->getAssets($composer, $assetDir, $packages);
            $this->assetManager->addDependencies($composer->getPackage(), $assets);

            $res = $this->assetManager->run();

            $dispatcher->dispatch(FoxyEvents::POST_SOLVE, new PostSolveEvent($assetDir, $packages, $res));

            if (0 !== $res) {
                throw new RuntimeException(
                    Message::SOLVER_ASSET_MANAGER_FAILED->getMessage($res),
                );
            }
        } catch (Throwable $exception) {
            $this->restoreComposerAfterFailure($exception);
        }
    }

    /**
     * Resolve existing symlinks and normalize a possibly non-existing absolute path.
     */
    private function canonicalizePath(string $path, string $baseDir): string
    {
        if (!$this->fs->isAbsolutePath($path)) {
            $path = rtrim($baseDir, '/\\') . '/' . $path;
        }

        $path = $this->fs->normalizePath($path);

        $suffix = [];
        $existingPath = $path;

        while (!file_exists($existingPath) && !is_link($existingPath)) {
            $parent = $this->fs->normalizePath(dirname($existingPath));

            if ($parent === $existingPath) {
                return $this->resolveCanonicalPath($existingPath, $path, $suffix);
            }

            array_unshift($suffix, basename($existingPath));

            $existingPath = $parent;
        }

        return $this->resolveCanonicalPath($existingPath, $path, $suffix);
    }

    /**
     * Get the package of asset dependencies.
     *
     * @param Composer $composer The composer instance.
     * @param string $assetDir The asset directory.
     * @param array $packages The package dependencies.
     *
     * @psalm-param PackageInterface[] $packages The package dependencies.
     *
     * @throws Exception
     */
    private function getAssets(Composer $composer, string $assetDir, array $packages): array
    {
        $installationManager = $composer->getInstallationManager();

        $configPackages = $this->config->getArray('enable-packages');

        $assets = [];

        foreach ($packages as $package) {
            $filename = AssetUtil::getPath($installationManager, $this->assetManager, $package, $configPackages);

            if (is_string($filename) && $filename !== '') {
                [$packageName, $packagePath] = $this->getMockPackagePath($package, $assetDir, $filename);
                $assets[$packageName] = $packagePath;
            }
        }

        $assetsEvent = new GetAssetsEvent($assetDir, $packages, $assets);

        $composer->getEventDispatcher()->dispatch(FoxyEvents::GET_ASSETS, $assetsEvent);

        return $assetsEvent->getAssets();
    }

    /**
     * Get the path of the mock package.
     *
     * @param PackageInterface $package The package dependency,
     * @param string $assetDir The asset directory.
     * @param string $filename The filename of asset package.
     *
     * @throws Exception if the asset package cannot be read or written.
     *
     * @return array{0: string, 1: string} The package name and absolute generated manifest path.
     */
    private function getMockPackagePath(PackageInterface $package, string $assetDir, string $filename): array
    {
        $packageName = AssetUtil::getName($package);

        $packagePath = "{$assetDir}/" . $package->getName();
        $newFilename = "{$packagePath}/" . basename($filename);

        try {
            $this->fs->ensureDirectoryExists($packagePath);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                Message::SOLVER_PACKAGE_DIR_CREATE_FAILED->getMessage($packagePath),
                0,
                $exception,
            );
        }

        $sourceContent = file_get_contents($filename);

        if (false === $sourceContent) {
            throw new RuntimeException(
                Message::SOLVER_MANIFEST_READ_FAILED->getMessage($filename),
            );
        }

        $packageValue = AssetUtil::formatPackage(
            $package,
            $packageName,
            (array) json_decode($sourceContent, false, flags: JSON_THROW_ON_ERROR),
        );

        $targetJsonFile = new JsonFile($newFilename);

        $targetJsonFile->write($packageValue);

        try {
            $targetJsonFile->read();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                Message::SOLVER_MANIFEST_WRITE_FAILED->getMessage($newFilename),
                0,
                $exception,
            );
        }

        return [$packageName, $newFilename];
    }

    /**
     * Returns the current working directory used as the project root.
     */
    private function getProjectDirectory(): string
    {
        $projectDir = getcwd();

        if (false === $projectDir) {
            throw new RuntimeException(
                Message::CURRENT_WORKING_DIRECTORY_UNAVAILABLE->getMessage(),
            );
        }

        return $projectDir;
    }

    /**
     * Reset an owned asset directory and create its ownership marker.
     */
    private function prepareAssetDirectory(string $assetDir, string $vendorDir): void
    {
        $defaultAssetDir = $this->canonicalizePath(
            "{$vendorDir}/php-forge/composer-asset",
            $this->getProjectDirectory(),
        );

        if (is_link($assetDir)) {
            throw new RuntimeException(
                Message::SOLVER_ASSET_DIR_SYMLINK->getMessage($assetDir),
            );
        }

        if (file_exists($assetDir) && !is_dir($assetDir)) {
            throw new RuntimeException(
                Message::SOLVER_ASSET_PATH_NOT_DIRECTORY->getMessage($assetDir),
            );
        }

        if (is_dir($assetDir)) {
            $entries = scandir($assetDir);

            if (false === $entries) {
                throw new RuntimeException(
                    Message::SOLVER_ASSET_DIR_INSPECT_FAILED->getMessage($assetDir),
                );
            }

            $isEmpty = [] === array_diff($entries, ['.', '..']);
            $isOwned = is_file("{$assetDir}/" . self::MANAGED_MARKER) || $assetDir === $defaultAssetDir;

            if (!$isEmpty && !$isOwned) {
                throw new RuntimeException(
                    Message::SOLVER_ASSET_DIR_UNMANAGED->getMessage($assetDir),
                );
            }

            $this->fs->remove($assetDir);

            if (file_exists($assetDir)) {
                throw new RuntimeException(
                    Message::SOLVER_ASSET_DIR_RESET_FAILED->getMessage($assetDir),
                );
            }
        }

        try {
            $this->fs->ensureDirectoryExists($assetDir);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                Message::SOLVER_ASSET_DIR_CREATE_FAILED->getMessage($assetDir),
                0,
                $exception,
            );
        }

        if (false === file_put_contents("{$assetDir}/" . self::MANAGED_MARKER, "Managed by php-forge/foxy.\n")) {
            throw new RuntimeException(
                Message::SOLVER_ASSET_DIR_MARK_FAILED->getMessage($assetDir),
            );
        }
    }

    /**
     * Resolve an existing path and append its non-existing suffix.
     *
     * @param string[] $suffix
     */
    private function resolveCanonicalPath(string $existingPath, string $path, array $suffix): string
    {
        $resolvedPath = realpath($existingPath);

        if (false === $resolvedPath) {
            throw new RuntimeException(
                Message::SOLVER_PATH_RESOLVE_FAILED->getMessage($path),
            );
        }

        if ([] === $suffix) {
            return $this->fs->normalizePath($resolvedPath);
        }

        return $this->fs->normalizePath(
            rtrim($resolvedPath, '/\\') . '/' . implode('/', $suffix),
        );
    }

    /**
     * Restore Composer state without hiding the original asset failure.
     */
    private function restoreComposerAfterFailure(Throwable $exception): never
    {
        if (null === $this->composerFallback) {
            throw $exception;
        }

        try {
            $this->composerFallback->restore();
        } catch (Throwable $fallbackException) {
            throw new RuntimeException(
                Message::SOLVER_COMPOSER_FALLBACK_RESTORE_FAILED->getMessage($fallbackException->getMessage()),
                0,
                $exception,
            );
        }

        throw $exception;
    }

    /**
     * Canonicalize and validate a directory before any recursive removal.
     */
    private function validateAssetDirectory(string $assetDir, string $vendorDir): string
    {
        $projectDir = $this->getProjectDirectory();

        if ('' === trim($assetDir)) {
            throw new RuntimeException(
                Message::SOLVER_ASSET_DIR_EMPTY->getMessage(),
            );
        }

        $configuredAssetDir = $this->fs->normalizePath($assetDir);

        if (is_link($configuredAssetDir)) {
            throw new RuntimeException(
                Message::SOLVER_ASSET_DIR_SYMLINK->getMessage($configuredAssetDir),
            );
        }

        $projectDir = $this->canonicalizePath($projectDir, $projectDir);
        $assetDir = $this->canonicalizePath($assetDir, $projectDir);
        $vendorDir = $this->canonicalizePath($vendorDir, $projectDir);
        $assetDirPrefix = str_ends_with($assetDir, '/') ? $assetDir : "{$assetDir}/";

        if ($this->fs->normalizePath(dirname($assetDir)) === $assetDir) {
            throw new RuntimeException(
                Message::SOLVER_ASSET_DIR_IS_ROOT->getMessage(),
            );
        }

        foreach ([$projectDir, $vendorDir] as $protectedPath) {
            if ($assetDir === $protectedPath || str_starts_with($protectedPath, $assetDirPrefix)) {
                throw new RuntimeException(
                    Message::SOLVER_ASSET_DIR_OVERLAPS_PROTECTED->getMessage($assetDir),
                );
            }
        }

        return $assetDir;
    }
}
