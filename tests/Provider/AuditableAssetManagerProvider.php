<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Asset\AuditableAssetManager} test cases.
 */
final class AuditableAssetManagerProvider
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function auditScopes(): iterable
    {
        yield 'all dependencies' => [false];
        yield 'production dependencies' => [true];
    }
}
