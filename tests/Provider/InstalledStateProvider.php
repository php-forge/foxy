<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Native\InstalledStateTest} test cases.
 */
final class InstalledStateProvider
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedFiles(): iterable
    {
        yield 'entry holding a list' => ['{"packages": {"a": ["1.0.0", "./a"]}}'];
        yield 'entry holding a number' => ['{"packages": {"a": {"version": 1, "file": "./a"}}}'];
        yield 'entry holding a string' => ['{"packages": {"a": "1.0.0"}}'];
        yield 'entry with an extra key' => ['{"packages": {"a": {"version": "1.0.0", "file": "./a", "x": "y"}}}'];
        yield 'entry without integrity' => ['{"packages": {"a": {"version": "1.0.0", "resolved": "https://r/a.tgz"}}}'];
        yield 'invalid JSON' => ['{'];
        yield 'later entry malformed' => [
            '{"packages": {"a": {"version": "1.0.0", "file": "./a"}, "b": {"version": "1.0.0"}}}',
        ];
        yield 'packages holding a string' => ['{"packages": "a"}'];
        yield 'packages missing' => ['{"dependencies": {}}'];
        yield 'root holding a string' => ['"packages"'];
    }
}
