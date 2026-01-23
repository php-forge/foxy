<?php

declare(strict_types=1);

namespace Foxy\Tests\Event;

use Foxy\Event\PreSolveEvent;

final class PreSolveEventTest extends SolveEvent
{
    public function getEvent(): PreSolveEvent
    {
        return new PreSolveEvent($this->assetDir, $this->packages);
    }
}
