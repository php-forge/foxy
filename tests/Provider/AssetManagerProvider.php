<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Asset\AssetManager} test cases.
 *
 * Provides enabled `run-asset-manager` values, non-concrete manager versions, and run exit codes per action.
 */
final class AssetManagerProvider
{
    /**
     * @return iterable<string, array{int|string}>
     */
    public static function enabledRunAssetManagerValues(): iterable
    {
        yield 'integer one' => [1];
        yield 'string one' => ['1'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonConcreteManagerVersions(): iterable
    {
        yield 'named version' => ['latest', 'default || *'];
        yield 'range' => ['>=1', '>=1'];
        yield 'wildcard' => ['*', '*'];
    }

    /**
     * @return iterable<int, array{int, string}>
     */
    public static function runOutcomes(): iterable
    {
        yield [0, 'install'];
        yield [0, 'update'];
        yield [1, 'install'];
        yield [1, 'update'];
        yield [-1, 'install'];
        yield [-1, 'update'];
    }
}
