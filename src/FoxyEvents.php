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

namespace Foxy;

/**
 * Events of Foxy.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
abstract class FoxyEvents
{
    /**
     * The "PRE_SOLVE" event is triggered before the `solve` action of asset packages.
     *
     * @Event("Foxy\Event\PreSolveEvent")
     */
    public const PRE_SOLVE = 'foxy.pre-solve';

    /**
     * The "GET_ASSETS" event is triggered before the `solve` action of asset packages
     * and during the retrieves the map of the asset packages.
     *
     * @Event("Foxy\Event\GetAssetsEvent")
     */
    public const GET_ASSETS = 'foxy.get-assets';

    /**
     * The "POST_SOLVE" event is triggered after the `solve` action of asset packages and before
     * the execution of the composer's fallback.
     *
     * @Event("Foxy\Event\PostSolveEvent")
     */
    public const POST_SOLVE = 'foxy.post-solve';
}
