<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Native\NativeInstallerTest} test cases.
 */
final class NativeInstallerProvider
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDependencyMaps(): iterable
    {
        yield 'dependencies holding a list' => [
            '{"dependencies": ["a"]}',
            'dependencies',
        ];
        yield 'dependencies holding a number spec' => [
            '{"dependencies": {"a": 1}}',
            'dependencies',
        ];
        yield 'dependencies holding a string' => [
            '{"dependencies": "a"}',
            'dependencies',
        ];
        yield 'devDependencies holding a traversal name' => [
            '{"devDependencies": {"../../src": "1.0.0"}}',
            'devDependencies',
        ];
        yield 'devDependencies holding an empty name' => [
            '{"devDependencies": {"": "1.0.0"}}',
            'devDependencies',
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mandatoryLocalFields(): iterable
    {
        yield 'dependencies' => ['dependencies'];
        yield 'peer dependencies' => ['peerDependencies'];
    }
}
