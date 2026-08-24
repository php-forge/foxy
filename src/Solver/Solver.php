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
use Foxy\Exception\RuntimeException;
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
use function realpath;
use function rtrim;
use function sprintf;
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
        private readonly AssetManagerInterface $assetManager,
        private readonly Config $config,
        private readonly Filesystem $fs,
        private readonly FallbackInterface|null $composerFallback = null,
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
        $enabled = $this->config->get('enabled');

        if ($enabled !== true && $enabled !== 1 && $enabled !== '1') {
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
                throw new RuntimeException('The Composer asset directory must be a string.');
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
                    sprintf('The asset manager ended with error code %d', $res),
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
                break;
            }

            array_unshift($suffix, basename($existingPath));

            $existingPath = $parent;
        }

        $resolvedPath = realpath($existingPath);

        if (false === $resolvedPath) {
            throw new RuntimeException(
                sprintf('Unable to resolve path "%s".', $path),
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
     * @throws Exception if the asset package cannot be copied or written.
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
                sprintf('Unable to create asset directory "%s".', $packagePath),
                0,
                $exception,
            );
        }

        if (!$this->fs->copy($filename, $newFilename)) {
            throw new RuntimeException(
                sprintf('Unable to copy asset manifest "%s".', $filename),
            );
        }

        $jsonFile = new JsonFile($newFilename);

        $packageValue = AssetUtil::formatPackage($package, $packageName, (array) $jsonFile->read());

        $jsonFile->write($packageValue);

        return [$packageName, $newFilename];
    }

    /**
     * Reset an owned asset directory and create its ownership marker.
     */
    private function prepareAssetDirectory(string $assetDir, string $vendorDir): void
    {
        $projectDir = getcwd();

        if (false === $projectDir) {
            throw new RuntimeException(
                'Unable to get the current working directory.',
            );
        }

        $defaultAssetDir = $this->canonicalizePath("{$vendorDir}/php-forge/composer-asset", $projectDir);

        if (is_link($assetDir)) {
            throw new RuntimeException(
                sprintf('The Composer asset directory "%s" must not be a symbolic link.', $assetDir),
            );
        }

        if (file_exists($assetDir) && !is_dir($assetDir)) {
            throw new RuntimeException(
                sprintf('The Composer asset path "%s" is not a directory.', $assetDir),
            );
        }

        if (is_dir($assetDir)) {
            $entries = scandir($assetDir);

            if (false === $entries) {
                throw new RuntimeException(
                    sprintf('Unable to inspect Composer asset directory "%s".', $assetDir),
                );
            }

            $isEmpty = [] === array_diff($entries, ['.', '..']);
            $isOwned = is_file("{$assetDir}/" . self::MANAGED_MARKER) || $assetDir === $defaultAssetDir;

            if (!$isEmpty && !$isOwned) {
                throw new RuntimeException(
                    sprintf(
                        'The Composer asset directory "%s" is not marked as managed by Foxy.',
                        $assetDir,
                    ),
                );
            }

            $this->fs->remove($assetDir);

            if (file_exists($assetDir)) {
                throw new RuntimeException(
                    sprintf('Unable to reset Composer asset directory "%s".', $assetDir),
                );
            }
        }

        try {
            $this->fs->ensureDirectoryExists($assetDir);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Unable to create Composer asset directory "%s".', $assetDir),
                0,
                $exception,
            );
        }

        if (false === file_put_contents("{$assetDir}/" . self::MANAGED_MARKER, "Managed by php-forge/foxy.\n")) {
            throw new RuntimeException(
                sprintf('Unable to mark Composer asset directory "%s".', $assetDir),
            );
        }
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
                sprintf('Asset solving failed and Composer fallback restoration failed: %s', $fallbackException->getMessage()),
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
        $projectDir = getcwd();

        if (false === $projectDir) {
            throw new RuntimeException(
                'Unable to get the current working directory.',
            );
        }

        if ('' === trim($assetDir)) {
            throw new RuntimeException(
                'The Composer asset directory must not be empty.',
            );
        }

        $configuredAssetDir = $this->fs->normalizePath($assetDir);

        if (is_link($configuredAssetDir)) {
            throw new RuntimeException(
                sprintf('The Composer asset directory "%s" must not be a symbolic link.', $configuredAssetDir),
            );
        }

        $projectDir = $this->canonicalizePath($projectDir, $projectDir);
        $assetDir = $this->canonicalizePath($assetDir, $projectDir);
        $vendorDir = $this->canonicalizePath($vendorDir, $projectDir);
        $assetDirPrefix = str_ends_with($assetDir, '/') ? $assetDir : "{$assetDir}/";

        if ($this->fs->normalizePath(dirname($assetDir)) === $assetDir) {
            throw new RuntimeException(
                'The Composer asset directory must not be a filesystem root.',
            );
        }

        foreach ([$projectDir, $vendorDir] as $protectedPath) {
            if ($assetDir === $protectedPath || str_starts_with($protectedPath, $assetDirPrefix)) {
                throw new RuntimeException(
                    sprintf('The Composer asset directory "%s" overlaps a protected project path.', $assetDir),
                );
            }
        }

        return $assetDir;
    }
}
