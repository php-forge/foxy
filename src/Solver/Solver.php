<?php

declare(strict_types=1);

/*
 * This file is part of the Foxy package.
 *
 * (c) François Pluchino <francois.pluchino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Foxy\Solver;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Foxy\Asset\AssetManagerInterface;
use Foxy\Config\Config;
use Foxy\Event\GetAssetsEvent;
use Foxy\Event\PostSolveEvent;
use Foxy\Event\PreSolveEvent;
use Foxy\Fallback\FallbackInterface;
use Foxy\FoxyEvents;
use Foxy\Util\AssetUtil;

/**
 * Solver of asset dependencies.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class Solver implements SolverInterface
{
    /**
     * Constructor.
     *
     * @param AssetManagerInterface $assetManager The asset manager instance.
     * @param Config $config The config instance.
     * @param null|FallbackInterface $composerFallback The composer fallback instance.
     */
    public function __construct(
        protected AssetManagerInterface $assetManager,
        protected Config $config,
        protected Filesystem $fs,
        protected FallbackInterface|null $composerFallback = null
    ) {
    }

    public function setUpdatable($updatable): self
    {
        $this->assetManager->setUpdatable($updatable);

        return $this;
    }

    public function solve(Composer $composer, IOInterface $io): void
    {
        if (!$this->config->get('enabled')) {
            return;
        }

        $dispatcher = $composer->getEventDispatcher();
        $packages = $composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $assetDir = $this->config->get('composer-asset-dir', $vendorDir . '/foxy/composer-asset/');
        $dispatcher->dispatch(FoxyEvents::PRE_SOLVE, new PreSolveEvent($assetDir, $packages));
        $this->fs->remove($assetDir);

        $assets = $this->getAssets($composer, $assetDir, $packages);
        $this->assetManager->addDependencies($composer->getPackage(), $assets);
        $res = $this->assetManager->run();
        $dispatcher->dispatch(FoxyEvents::POST_SOLVE, new PostSolveEvent($assetDir, $packages, $res));

        if ($res > 0 && $this->composerFallback) {
            $this->composerFallback->restore();

            throw new \RuntimeException('The asset manager ended with an error');
        }
    }

    /**
     * Get the package of asset dependencies.
     *
     * @param Composer  $composer The composer instance.
     * @param string $assetDir The asset directory.
     * @param array $packages The package dependencies.
     *
     * @psalm-param PackageInterface[] $packages The package dependencies.
     * @psalm-return array[] The package name and the relative package path from the current directory.
     */
    protected function getAssets(Composer $composer, string $assetDir, array $packages): array
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
     */
    protected function getMockPackagePath(PackageInterface $package, string $assetDir, string $filename): array
    {
        $packageName = AssetUtil::getName($package);
        $packagePath = \rtrim($assetDir, '/') . '/' . $package->getName();
        $newFilename = $packagePath . '/' . \basename($filename);

        \mkdir($packagePath, 0777, true);
        \copy($filename, $newFilename);

        $jsonFile = new JsonFile($newFilename);
        $packageValue = AssetUtil::formatPackage($package, $packageName, (array) $jsonFile->read());

        $jsonFile->write($packageValue);

        return [$packageName, $this->fs->findShortestPath(getcwd(), $newFilename)];
    }
}
