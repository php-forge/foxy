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

namespace Foxy\Fallback;

/**
 * Interface of fallback.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
interface FallbackInterface
{
    /**
     * Save the state.
     */
    public function save(): self;

    /**
     * Restore the state.
     */
    public function restore(): void;
}
