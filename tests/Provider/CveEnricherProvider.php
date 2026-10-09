<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Audit\CveEnricherTest} test cases.
 */
final class CveEnricherProvider
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function advisoryIdsWithSurroundingText(): iterable
    {
        yield 'prefix' => ['prefix-GHSA-35jh-r3h4-6jhm'];
        yield 'suffix' => ['GHSA-35jh-r3h4-6jhm-suffix'];
    }
}
