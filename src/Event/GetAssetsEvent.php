<?php

declare(strict_types=1);

namespace Foxy\Event;

use Composer\Package\PackageInterface;
use Foxy\FoxyEvents;

final class GetAssetsEvent extends AbstractSolveEvent
{
    /**
     * @param string $assetDir The directory of mock assets.
     * @param PackageInterface[] $packages All installed Composer packages.
     * @param array<mixed> $assets The map of asset package name and the asset package path.
     */
    public function __construct(string $assetDir, array $packages, private array $assets = [])
    {
        parent::__construct(FoxyEvents::GET_ASSETS, $assetDir, $packages);
    }

    /**
     * Add the asset package.
     *
     * @param string $name The asset package name.
     * @param string $path A project-relative package manifest path or a ready `file:` directory reference.
     *
     * Example:
     *
     * For the Composer package `foo/bar`.
     *
     * $event->addAsset('@composer-asset/foo--bar', 'vendor/foo/bar/package.json');
     */
    public function addAsset(string $name, string $path): self
    {
        $this->assets[$name] = $path;

        return $this;
    }

    /**
     * Get the map of asset package name and the asset package path.
     */
    public function getAssets(): array
    {
        return $this->assets;
    }

    /**
     * Check if the asset package is present.
     *
     * @param string $name The asset package name
     */
    public function hasAsset(string $name): bool
    {
        return isset($this->assets[$name]);
    }
}
