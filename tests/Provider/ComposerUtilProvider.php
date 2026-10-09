<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Util\ComposerUtilTest} test cases.
 */
final class ComposerUtilProvider
{
    /**
     * @return iterable<int, array{string, string, bool}>
     */
    public static function composerVersions(): iterable
    {
        yield ['@package_version@', '^1.5.0', true];
        yield ['@package_version@', '^1.5.0|^2.0.0', true];
        yield ['d173af2d7ac1408655df2cf6670ea0262e06d137', '^1.5.0|^2.0.0', true];
        yield ['D173AF2D7AC1408655DF2CF6670EA0262E06D137', '^1.5.0|^2.0.0', true];
        yield ['1.6.0', '^1.5.0', true];
        yield ['1.5.1', '^1.5.0', true];
        yield ['1.5.0', '^1.5.0', true];
        yield ['1.5.0', '^1.5.0|^2.0.0', true];
        yield ['1.5.0', '^1.5.1', false];
        yield ['1.0.0', '^1.5.0', false];
    }
}
