<?php

declare(strict_types=1);

namespace Foxy;

abstract class FoxyEvents
{
    /**
     * The `GET_ASSETS` event is triggered before the `solve` action of asset packages and while retrieving the map
     * of the asset packages.
     */
    final public const GET_ASSETS = 'foxy.get-assets';

    /**
     * The `POST_SOLVE` event is triggered after the `solve` action of asset packages and before the execution of the
     * composer's fallback.
     */
    final public const POST_SOLVE = 'foxy.post-solve';

    /**
     * The `PRE_SOLVE` event is triggered before the `solve` action of asset packages.
     */
    final public const PRE_SOLVE = 'foxy.pre-solve';
}
