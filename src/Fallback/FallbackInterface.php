<?php

declare(strict_types=1);

namespace Foxy\Fallback;

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
