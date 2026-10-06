<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use Foxy\Tests\Audit\AuditFixture;

/**
 * Data provider for {@see \Foxy\Tests\Audit\AuditRunnerTest} test cases.
 */
final class AuditRunnerProvider
{
    use AuditFixture;

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function bunPartialReports(): iterable
    {
        yield 'native status one and vulnerable body' => [1, self::fixture('bun-populated.json')];
        yield 'native status zero and clean body' => [0, self::fixture('bun-clean.json')];
    }
}
