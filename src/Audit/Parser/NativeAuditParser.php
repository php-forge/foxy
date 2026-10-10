<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

/**
 * Parses the bulk advisories document that the `native` manager fetches from the configured npm registry.
 */
final class NativeAuditParser extends AbstractBulkAdvisoryParser
{
    protected function getManagerName(): string
    {
        return 'native';
    }
}
