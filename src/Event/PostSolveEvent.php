<?php

declare(strict_types=1);

namespace Foxy\Event;

use Composer\Package\PackageInterface;
use Foxy\FoxyEvents;

final class PostSolveEvent extends AbstractSolveEvent
{
    /**
     * @param string $assetDir The directory of mock assets.
     * @param array $packages  All installed Composer packages.
     * @param int $runResult The process result of asset manager execution.
     *
     * @psalm-param PackageInterface[] $packages All installed Composer packages.
     */
    public function __construct(string $assetDir, array $packages, private readonly int $runResult)
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
