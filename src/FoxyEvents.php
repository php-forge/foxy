<?php

declare(strict_types=1);

namespace Foxy;

use Event;

abstract class FoxyEvents
{
    /**
     * The "GET_ASSETS" event is triggered before the `solve` action of asset packages
     * and during the retrieves the map of the asset packages.
     *
     * @Event("Foxy\Event\GetAssetsEvent")
     */
    final public const GET_ASSETS = 'foxy.get-assets';

    /**
     * The "POST_SOLVE" event is triggered after the `solve` action of asset packages and before
     * the execution of the composer's fallback.
     *
     * @Event("Foxy\Event\PostSolveEvent")
     */
    final public const POST_SOLVE = 'foxy.post-solve';

    /**
     * The "PRE_SOLVE" event is triggered before the `solve` action of asset packages.
     *
     * @Event("Foxy\Event\PreSolveEvent")
     */
    final public const PRE_SOLVE = 'foxy.pre-solve';
}
