<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Command\FoxyCommandProviderTest} test cases.
 */
final class FoxyCommandProviderProvider
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'composer' => ['composer'];
        yield 'io' => ['io'];
        yield 'plugin' => ['plugin'];
    }
}
