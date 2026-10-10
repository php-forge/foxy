<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Native\DependencyResolverTest} test cases.
 */
final class DependencyResolverProvider
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedSpecs(): iterable
    {
        yield 'file protocol' => ['file:../widgets'];
        yield 'git url' => ['git+https://github.com/acme/widgets.git'];
        yield 'git-prefixed word' => ['gitlab'];
        yield 'github shorthand' => ['github:acme/widgets'];
        yield 'http-prefixed word' => ['https'];
        yield 'npm alias' => ['npm:widgets@1'];
        yield 'url fragment' => ['#v1.0.0'];
        yield 'user and repository' => ['acme/widgets'];
        yield 'workspace protocol' => ['workspace:*'];
    }
}
