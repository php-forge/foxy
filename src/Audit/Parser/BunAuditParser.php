<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

/**
 * Parses the `bun audit --json` report, which is the npm registry's bulk advisories document.
 */
final class BunAuditParser extends AbstractBulkAdvisoryParser
{
    protected function getManagerName(): string
    {
        return 'bun';
    }
}
