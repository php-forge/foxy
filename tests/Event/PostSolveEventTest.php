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

use Foxy\Event\PostSolveEvent;

/**
 * Tests for post solve event.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class PostSolveEventTest extends SolveEvent
{
    public function getEvent(): PostSolveEvent
    {
        return new PostSolveEvent($this->assetDir, $this->packages, 42);
    }

    public function testGetRunResult(): void
    {
        $event = $this->getEvent();

        $this->assertSame(42, $event->getRunResult());
    }
}
