<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use Foxy\Audit\{AuditFormat, Severity};
use Foxy\Command\AuditCommand;

/**
 * Data provider for {@see \Foxy\Tests\Command\AuditCommandTest} test cases.
 */
final class AuditCommandProvider
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function auditFormats(): iterable
    {
        yield 'json' => [AuditFormat::JSON->value];
        yield 'plain' => [AuditFormat::PLAIN->value];
        yield 'summary' => [AuditFormat::SUMMARY->value];
        yield 'table' => [AuditFormat::TABLE->value];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAuditLevels(): iterable
    {
        yield 'informational is not a failure threshold' => [Severity::INFO->value];
        yield 'unknown severity' => ['severe'];
    }

    /**
     * @return iterable<string, array{Severity, Severity, int}>
     */
    public static function thresholdStatuses(): iterable
    {
        yield 'above threshold' => [Severity::CRITICAL, Severity::HIGH, AuditCommand::STATUS_VULNERABLE];
        yield 'at threshold' => [Severity::HIGH, Severity::HIGH, AuditCommand::STATUS_VULNERABLE];
        yield 'below threshold' => [Severity::MODERATE, Severity::HIGH, AuditCommand::STATUS_OK];
    }
}
