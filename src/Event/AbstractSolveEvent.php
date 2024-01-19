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

use Composer\EventDispatcher\Event;
use Composer\Package\PackageInterface;

/**
 * Abstract event for solve event.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
abstract class AbstractSolveEvent extends Event
{
    /**
     * Constructor.
     *
     * @param string $name The event name.
     * @param string $assetDir The directory of mock assets.
     * @param array $packages All installed Composer packages.
     * 
     * @psalm-param PackageInterface[] $packages All installed Composer packages.
     */
    public function __construct(string $name, private string $assetDir, private array $packages = [])
    {
        parent::__construct($name, [], []);
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
