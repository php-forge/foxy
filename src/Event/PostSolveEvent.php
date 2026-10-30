<?php

declare(strict_types=1);

namespace Foxy\Event;

use Composer\Package\PackageInterface;
use Foxy\FoxyEvents;

/**
 * Reports the asset-manager result after dependency solving.
 */
final class PostSolveEvent extends AbstractSolveEvent
{
    /**
     * Creates the post-solve event.
     *
     * @param string $assetDir The directory of mock assets.
     * @param PackageInterface[] $packages  All installed Composer packages.
     * @param int $runResult The process result of asset manager execution.
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
