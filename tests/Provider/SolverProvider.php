<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Solver\SolverTest} test cases.
 */
final class SolverProvider
{
    /**
     * @return iterable<int, array{int}>
     */
    public static function runManagerResults(): iterable
    {
        yield [0];
        yield [1];
    }
}
