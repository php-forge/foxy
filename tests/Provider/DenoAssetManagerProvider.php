<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Asset\DenoAssetManagerTest} test cases.
 *
 * Provides `workspaces` field shapes and Composer asset `file:` dependency paths for Deno workspace synchronization.
 */
final class DenoAssetManagerProvider
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function insideRootPackageDirectory(): iterable
    {
        yield 'backslash separators' => ['file:vendor\\foo\\bar', 'vendor/foo/bar'];
        yield 'current directory prefix' => ['file:./vendor/foo/bar', 'vendor/foo/bar'];
        yield 'directory starting with two dots' => ['file:./..assets/foo', '..assets/foo'];
        yield 'embedded traversal staying inside the root' => ['file:assets/../vendor/foo/bar', 'vendor/foo/bar'];
        yield 'nested segment containing a colon' => ['file:./assets/c:foo', 'assets/c:foo'];
        yield 'relative path without prefix' => ['file:vendor/foo/bar', 'vendor/foo/bar'];
        yield 'trailing slash' => ['file:./vendor/foo/bar/', 'vendor/foo/bar'];
    }

    /**
     * @return iterable<string, array{array<string, list<string>>|array<string, string>|list<int|string>|string}>
     */
    public static function invalidWorkspaces(): iterable
    {
        yield 'list with a non-string member' => [['packages/*', 1]];
        yield 'map of strings' => [['web' => 'packages/web']];
        yield 'object form' => [['packages' => ['packages/*']]];
        yield 'scalar' => ['packages/*'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonNestedAssetPaths(): iterable
    {
        yield 'absolute path' => ['file:/vendor/foo/bar'];
        yield 'backslash traversal' => ['file:..\\outside'];
        yield 'embedded traversal escaping the root' => ['file:assets/../../outside'];
        yield 'embedded traversal reaching the parent' => ['file:assets/../..'];
        yield 'nested parent directory' => ['file:../vendor/foo/bar'];
        yield 'parent directory' => ['file:..'];
        yield 'root package directory' => ['file:.'];
        yield 'UNC path' => ['file:\\\\server\\share'];
        yield 'Windows drive' => ['file:C:/vendor/foo/bar'];
    }

    /**
     * @return iterable<string, array{array<string, list<string>>|list<string>}>
     */
    public static function unmanagedWorkspaces(): iterable
    {
        yield 'empty list' => [[]];
        yield 'object form' => [['packages' => ['packages/*']]];
    }
}
