<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Util\ConsoleUtilTest} test cases.
 */
final class ConsoleUtilProvider
{
    /**
     * @return iterable<int, array{bool, bool, string, bool|list<bool|int|null>}>
     */
    public static function preferredInstallOptions(): iterable
    {
        yield [false, false, 'auto', false];
        yield [false, true, 'auto', [false, true]];
        yield [true, false, 'source', false];
        yield [true, false, 'source', [false, null]];
        yield [false, true, 'dist', false];
        yield [true, false, 'auto', [1, 0]];
    }
}
