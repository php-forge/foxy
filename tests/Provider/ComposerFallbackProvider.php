<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Fallback\ComposerFallbackTest} test cases.
 */
final class ComposerFallbackProvider
{
    /**
     * @return iterable<string, array{string, bool|list<string>}>
     */
    public static function ignorePlatformReqOptions(): iterable
    {
        yield 'ignore-platform-req is true' => ['ignore-platform-req', true];
        yield 'ignore-platform-req is array' => ['ignore-platform-req', ['php', 'ext-json']];
    }

    /**
     * @return iterable<string, array{string, bool|list<string>}>
     */
    public static function ignorePlatformReqsOptions(): iterable
    {
        yield 'ignore-platform-reqs is true' => ['ignore-platform-reqs', true];
        yield 'ignore-platform-reqs is array' => ['ignore-platform-reqs', ['php', 'ext-json']];
    }

    /**
     * @return iterable<string, array{bool|int|string, bool, bool}>
     */
    public static function installerBooleanOptions(): iterable
    {
        yield 'both disabled' => [false, false, false];
        yield 'input enabled' => [true, false, true];
        yield 'config enabled' => [false, true, true];
        yield 'both enabled' => [true, true, true];
        yield 'integer one enabled' => [1, false, true];
        yield 'string one enabled' => ['1', false, true];
        yield 'other integer disabled' => [2, false, false];
    }

    /**
     * @return iterable<int, array{list<array{name: string, version: string}>}>
     */
    public static function lockedPackages(): iterable
    {
        yield [[]];
        yield [[['name' => 'foo/bar', 'version' => '1.0.0.0']]];
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
