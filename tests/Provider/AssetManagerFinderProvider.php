<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Asset\AssetManagerFinderTest} test cases.
 */
final class AssetManagerFinderProvider
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function availabilityChecks(): iterable
    {
        yield 'disabled' => [false];
        yield 'enabled' => [true];
    }
}
