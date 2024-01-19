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
 * Post solve event.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class PostSolveEvent extends AbstractSolveEvent
{
    /**
     * Constructor.
     *
     * @param string $assetDir The directory of mock assets.
     * @param array $packages  All installed Composer packages.
     * @param int $runResult The process result of asset manager execution.
     * 
     * @psalm-param PackageInterface[] $packages All installed Composer packages.
     */
    public function __construct($assetDir, array $packages, private int $runResult)
    {
        parent::__construct(FoxyEvents::POST_SOLVE, $assetDir, $packages);
    }

    /**
     * Get the process result of asset manager execution.
     */
    public function getRunResult(): int
    {
        return $this->runResult;
    }
}
