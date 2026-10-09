<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Fallback\AssetFallbackTest} test cases.
 */
final class AssetFallbackProvider
{
    /**
     * @return iterable<string, array{string|null}>
     */
    public static function originalManifests(): iterable
    {
        yield 'empty original manifest' => [''];
        yield 'no original manifest' => [null];
        yield 'non-empty original manifest' => ['{}'];
    }

    /**
     * @return iterable<int, array{bool}>
     */
    public static function snapshotScenarios(): iterable
    {
        yield [true];
        yield [false];
    }
}
