<?php

declare(strict_types=1);

namespace Foxy\Audit;

use Foxy\Audit\Parser\{BunAuditParser, DenoAuditParser, NpmAuditParser, PnpmAuditParser, YarnAuditParser};
use Foxy\Exception\{Message, RuntimeException};

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
                Message::AUDIT_PARSER_UNSUPPORTED_MANAGER->getMessage($manager),
            ),
        };
    }
}
