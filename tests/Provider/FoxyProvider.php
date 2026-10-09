<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\FoxyTest} test cases.
 */
final class FoxyProvider
{
    /**
     * @return iterable<string, array{bool|int|string, bool}>
     */
    public static function runAssetManagerValues(): iterable
    {
        yield 'boolean true' => [true, true];
        yield 'integer one' => [1, true];
        yield 'string one' => ['1', true];
        yield 'boolean false' => [false, false];
        yield 'integer two' => [2, false];
        yield 'string two' => ['2', false];
    }

    /**
     * @return iterable<int, array{string, bool}>
     */
    public static function solveEvents(): iterable
    {
        yield ['solve_event_install', false];
        yield ['solve_event_update', true];
    }
}
