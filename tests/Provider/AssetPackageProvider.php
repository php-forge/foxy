<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Asset\AssetPackageTest} test cases.
 *
 * Provides expected manifests, input manifests, and root package licenses for required key injection.
 */
final class AssetPackageProvider
{
    /**
     * @return iterable<int, array{array<string, bool|string>, array<string, string>, string}>
     */
    public static function requiredKeys(): iterable
    {
        yield [
            ['name' => '@foo/bar', 'license' => 'MIT'],
            ['name' => '@foo/bar', 'license' => 'MIT'],
            'proprietary',
        ];
        yield [
            ['name' => '@foo/bar', 'license' => 'MIT'],
            ['name' => '@foo/bar'],
            'MIT',
        ];
        yield [
            ['name' => '@foo/bar', 'private' => true],
            ['name' => '@foo/bar'],
            'proprietary',
        ];
    }
}
