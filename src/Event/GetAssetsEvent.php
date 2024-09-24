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

namespace Foxy\Event;

use Composer\Package\PackageInterface;
use Foxy\FoxyEvents;

/**
 * Get assets event.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class GetAssetsEvent extends AbstractSolveEvent
{
    /**
     * @param string $assetDir The directory of mock assets.
     * @param array $packages All installed Composer packages.
     * @param array $assets The map of asset package name and the asset package path.
     *
     * @psalm-param PackageInterface[] $packages All installed Composer packages.
     */
    public function __construct(string $assetDir, array $packages, private array $assets = [])
    {
        parent::__construct(FoxyEvents::GET_ASSETS, $assetDir, $packages);

        $this->assets = $assets;
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

    /**
     * Add the asset package.
     *
     * @param string $name The asset package name.
     * @param string $path The asset package path (relative path form root project and started with `file:`).
     *
     * Example:
     *
     * For the Composer package `foo/bar`.
     *
     * $event->addAsset('@composer-asset/foo--bar', 'file:./vendor/foxy/composer-asset/foo/bar');
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
}
