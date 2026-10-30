<?php

declare(strict_types=1);

namespace Foxy\Fallback;

/**
 * Captures and restores state around asset dependency solving.
 */
interface FallbackInterface
{
    /**
     * Restore the state.
     */
    public function restore(): void;

    /**
     * Save the state.
     */
    public function save(): self;
}
