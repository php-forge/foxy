<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use function str_repeat;

/**
 * Data provider for {@see \Foxy\Tests\Native\PackageNameTest} test cases.
 */
final class PackageNameProvider
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'absolute path' => ['/abs'];
        yield 'backslash separator' => ['a\\b'];
        yield 'current directory' => ['.'];
        yield 'empty name' => [''];
        yield 'empty scope' => ['@/x'];
        yield 'leading dot' => ['.hidden'];
        yield 'leading underscore' => ['_private'];
        yield 'name longer than 214 bytes' => [str_repeat('a', 215)];
        yield 'parent directory' => ['..'];
        yield 'relative traversal' => ['../../src'];
        yield 'scope with empty name' => ['@scope/'];
        yield 'scope without name' => ['@scope'];
        yield 'slash outside scope' => ['a/b'];
        yield 'space' => ['a b'];
        yield 'trailing newline' => ["bootstrap\n"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNames(): iterable
    {
        yield 'mixed case' => ['JSONStream'];
        yield 'name of 214 bytes' => [str_repeat('a', 214)];
        yield 'punctuation' => ['a.b-c_d'];
        yield 'scoped name' => ['@popperjs/core'];
        yield 'simple name' => ['bootstrap'];
    }
}
