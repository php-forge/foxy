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

namespace Foxy\Tests\Event;

use Foxy\Event\PreSolveEvent;

/**
 * Tests for pre solve event.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class PreSolveEventTest extends SolveEvent
{
    public function getEvent(): PreSolveEvent
    {
        return new PreSolveEvent($this->assetDir, $this->packages);
    }
}
