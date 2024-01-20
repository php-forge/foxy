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
 * Pre solve event.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class PreSolveEvent extends AbstractSolveEvent
{
    /**
     * Constructor.
     *
     * @param string $assetDir The directory of mock assets.
     * @param array $packages All installed Composer packages.
     *
     * @psalm-param PackageInterface[] $packages All installed Composer packages.
     */
    public function __construct(string $assetDir, array $packages = [])
    {
        parent::__construct(FoxyEvents::PRE_SOLVE, $assetDir, $packages);
    }
}
