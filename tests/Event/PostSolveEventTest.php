<?php

declare(strict_types=1);

namespace Foxy\Tests\Event;

use Foxy\Event\PostSolveEvent;

final class PostSolveEventTest extends SolveEvent
{
    public function getEvent(): PostSolveEvent
    {
        return new PostSolveEvent($this->assetDir, $this->packages, 42);
    }

    public function testGetRunResult(): void
    {
        $event = $this->getEvent();

        self::assertSame(
            42,
            $event->getRunResult(),
        );
    }
}
