<?php

declare(strict_types=1);

namespace Foxy\Audit;

use Foxy\Audit\Parser\{BunAuditParser, DenoAuditParser, NpmAuditParser, PnpmAuditParser, YarnAuditParser};
use Foxy\Exception\RuntimeException;

use function sprintf;

abstract class AuditParserFactory
{
    public static function create(string $manager): AuditParserInterface
    {
        return match ($manager) {
            'npm' => new NpmAuditParser(),
            'pnpm' => new PnpmAuditParser(),
            'yarn' => new YarnAuditParser(),
            'bun' => new BunAuditParser(),
            'deno' => new DenoAuditParser(),
            default => throw new RuntimeException(
                sprintf('The asset manager "%s" does not provide a supported audit report.', $manager),
            ),
        };
    }
}
