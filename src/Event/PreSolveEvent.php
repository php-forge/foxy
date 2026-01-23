<?php

declare(strict_types=1);

namespace Foxy\Event;

use Composer\Package\PackageInterface;
use Foxy\FoxyEvents;

final class PreSolveEvent extends AbstractSolveEvent
{
    /**
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
