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
use Foxy\Fallback\FallbackInterface;
use Foxy\FoxyEvents;
use Foxy\Util\AssetUtil;
use RuntimeException;

use function basename;
use function copy;
use function mkdir;
use function rtrim;

final class Solver implements SolverInterface
{
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
    ) {
    }

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
        if (!$this->config->get('enabled')) {
            return;
        }

        $dispatcher = $composer->getEventDispatcher();
        $packages = $composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        /** @var string $vendorDir */
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        /** @var string $assetDir */
        $assetDir = $this->config->get('composer-asset-dir', $vendorDir . '/php-forge/composer-asset/');
        $dispatcher->dispatch(FoxyEvents::PRE_SOLVE, new PreSolveEvent($assetDir, $packages));
        $this->fs->remove($assetDir);
        $assets = $this->getAssets($composer, $assetDir, $packages);
        $this->assetManager->addDependencies($composer->getPackage(), $assets);
        $res = $this->assetManager->run();
        $dispatcher->dispatch(FoxyEvents::POST_SOLVE, new PostSolveEvent($assetDir, $packages, $res));

        if ($res > 0 && $this->composerFallback) {
            $this->composerFallback->restore();

            throw new RuntimeException('The asset manager ended with an error');
        }
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

            if (null !== $filename) {
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
     * @psalm-return string[] The package name and the relative package path from the current directory
     *
     * @throws Exception
     */
    private function getMockPackagePath(PackageInterface $package, string $assetDir, string $filename): array
    {
        $packageName = AssetUtil::getName($package);
        $packagePath = rtrim($assetDir, '/') . '/' . $package->getName();
        $newFilename = $packagePath . '/' . basename($filename);

        mkdir($packagePath, 0777, true);
        copy($filename, $newFilename);

        $jsonFile = new JsonFile($newFilename);
        $packageValue = AssetUtil::formatPackage($package, $packageName, (array) $jsonFile->read());

        $jsonFile->write($packageValue);

        return [$packageName, $this->fs->findShortestPath(getcwd(), $newFilename)];
    }
}
