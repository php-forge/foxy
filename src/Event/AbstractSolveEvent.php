<?php

declare(strict_types=1);

namespace Foxy\Event;

use Composer\EventDispatcher\Event;
use Composer\Package\PackageInterface;

abstract class AbstractSolveEvent extends Event
{
    /**
     * @param string $name The event name.
     * @param string $assetDir The directory of mock assets.
     * @param array $packages All installed Composer packages.
     *
     * @psalm-param PackageInterface[] $packages All installed Composer packages.
     */
    public function __construct(string $name, private readonly string $assetDir, private readonly array $packages = [])
    {
        parent::__construct($name);
    }

    /**
     * Get the directory of mock assets.
     */
    public function getAssetDir(): string
    {
        return $this->assetDir;
    }

    /**
     * Get the installed Composer packages.
     *
     * @psalm-return PackageInterface[] All installed Composer packages.
     */
    public function getPackages(): array
    {
        return $this->packages;
    }
}
