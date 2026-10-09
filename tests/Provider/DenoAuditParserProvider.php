<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use Foxy\Audit\Severity;
use Foxy\Exception\Message;

use function implode;
use function sprintf;
use function str_repeat;
use function str_replace;

/**
 * Data provider for {@see \Foxy\Tests\Audit\DenoAuditParserTest} test cases.
 */
final class DenoAuditParserProvider
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function equivalentLayouts(): iterable
    {
        yield 'carriage return line endings' => ["\r", '', ''];
        yield 'CRLF line endings' => ["\r\n", '', ''];
        yield 'surrounding whitespace' => ["\n", " \n\t", "\n  "];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedReports(): iterable
    {
        yield 'advisory with a padded value' => [
            self::report(
                str_replace('│ Package:    lodash', '│ Package:    lodash ', self::advisory()),
                self::summary(1, 0, 0, 1, 0),
            ),
            Message::AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED->getMessage('advisory 1', 3),
        ];
        yield 'advisory with a wrong closing box character' => [
            self::report(str_replace('╰ Info:', '└ Info:', self::advisory()), self::summary(1, 0, 0, 1, 0)),
            Message::AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED->getMessage('advisory 1', 5),
        ];
        yield 'advisory with a wrong opening box character' => [
            self::report(str_replace('╭ ', '┌ ', self::advisory()), self::summary(1, 0, 0, 1, 0)),
            Message::AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED->getMessage('advisory 1', 1),
        ];
        yield 'advisory with an empty package' => [
            self::report(
                str_replace('│ Package:    lodash', '│ Package:    ', self::advisory()),
                self::summary(1, 0, 0, 1, 0),
            ),
            Message::AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED->getMessage('advisory 1', 3),
        ];
        yield 'advisory with an info severity' => [
            self::report(self::advisory('info'), self::summary(1, 0, 0, 0, 0)),
            Message::AUDIT_VALUE_SEVERITY_UNSUPPORTED->getMessage('advisory 1'),
        ];
        yield 'advisory with an unknown extra line' => [
            self::report(self::advisory() . "\n│ Fixed:      4.17.21", self::summary(1, 0, 0, 1, 0)),
            Message::AUDIT_DENO_ADVISORY_LINE_COUNT_INVALID->getMessage('advisory 1', 5, 7),
        ];
        yield 'advisory with an unsupported severity' => [
            self::report(self::advisory('severe'), self::summary(1, 0, 0, 1, 0)),
            Message::AUDIT_VALUE_SEVERITY_UNSUPPORTED->getMessage('advisory 1'),
        ];
        yield 'advisory with reordered labels' => [
            self::report(
                "╭ Command Injection in lodash\n"
                . "│ Package:    lodash\n"
                . "│ Severity:   high\n"
                . "│ Vulnerable: <4.17.21\n"
                . '╰ Info:       https://github.com/advisories/GHSA-35jh-r3h4-6jhm',
                self::summary(1, 0, 0, 1, 0),
            ),
            Message::AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED->getMessage('advisory 1', 2),
        ];
        yield 'advisory with shifted label padding' => [
            self::report(
                str_replace('│ Severity:   ', '│ Severity: ', self::advisory()),
                self::summary(1, 0, 0, 1, 0),
            ),
            Message::AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED->getMessage('advisory 1', 2),
        ];
        yield 'advisory without the info line' => [
            self::report(
                "╭ Command Injection in lodash\n│ Severity:   high\n│ Package:    lodash\n│ Vulnerable: <4.17.21",
                self::summary(1, 0, 0, 1, 0),
            ),
            Message::AUDIT_DENO_ADVISORY_LINE_COUNT_INVALID->getMessage('advisory 1', 5, 7),
        ];
        yield 'ANSI-coloured advisory value' => [
            self::report(self::advisory("\e[31mhigh\e[0m"), self::summary(1, 0, 0, 1, 0)),
            Message::AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED->getMessage('advisory 1', 2),
        ];
        yield 'ANSI-coloured clean report' => [
            "\e[32mNo known vulnerabilities found\e[0m\n",
            Message::AUDIT_DENO_SUMMARY_UNRECOGNIZED->getMessage(),
        ];
        yield 'ANSI-coloured summary' => [
            self::report(self::advisory(), "\e[1m" . self::summary(1, 0, 0, 1, 0) . "\e[0m"),
            Message::AUDIT_DENO_SUMMARY_UNRECOGNIZED->getMessage(),
        ];
        yield 'empty output' => ['', Message::AUDIT_DENO_REPORT_EMPTY->getMessage()];
        yield 'extra blank line between advisories' => [
            self::report(self::advisory() . "\n", self::advisory(), self::summary(2, 0, 0, 2, 0)),
            Message::AUDIT_DENO_ADVISORY_LINE_COUNT_INVALID->getMessage('advisory 2', 5, 7),
        ];
        yield 'low severity count mismatch' => [
            self::report(self::advisory(), self::summary(1, 1, 0, 0, 0)),
            Message::AUDIT_DENO_SUMMARY_SEVERITY_MISMATCH->getMessage(1, 'low', 0),
        ];
        yield 'missing summary' => [
            self::report(self::advisory()),
            Message::AUDIT_DENO_SUMMARY_UNRECOGNIZED->getMessage(),
        ];
        yield 'patched advisory closed by the info line' => [
            self::report(str_replace('│ Info:', '╰ Info:', self::patchedAdvisory()), self::summary(1, 0, 0, 1, 0)),
            Message::AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED->getMessage('advisory 1', 6),
        ];
        yield 'patched advisory with a mismatched action package' => [
            self::report(self::patchedAdvisory('update underscore to >=4.17.21'), self::summary(1, 0, 0, 1, 0)),
            Message::AUDIT_DENO_ADVISORY_ACTIONS_MISMATCH->getMessage('advisory 1'),
        ];
        yield 'patched advisory with a mismatched action version' => [
            self::report(self::patchedAdvisory('update lodash to >=4.17.20'), self::summary(1, 0, 0, 1, 0)),
            Message::AUDIT_DENO_ADVISORY_ACTIONS_MISMATCH->getMessage('advisory 1'),
        ];
        yield 'patched advisory with an empty patched range' => [
            self::report(
                str_replace('│ Patched:    >=4.17.21', '│ Patched:    ', self::patchedAdvisory()),
                self::summary(1, 0, 0, 1, 0),
            ),
            Message::AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED->getMessage('advisory 1', 5),
        ];
        yield 'patched advisory with swapped patched and info lines' => [
            self::report(
                "╭ Command Injection in lodash\n"
                . "│ Severity:   high\n"
                . "│ Package:    lodash\n"
                . "│ Vulnerable: <4.17.21\n"
                . "│ Info:       https://github.com/advisories/GHSA-35jh-r3h4-6jhm\n"
                . "│ Patched:    >=4.17.21\n"
                . '╰ Actions:    update lodash to >=4.17.21',
                self::summary(1, 0, 0, 1, 0),
            ),
            Message::AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED->getMessage('advisory 1', 5),
        ];
        yield 'patched advisory without the actions line' => [
            self::report(
                str_replace("\n╰ Actions:    update lodash to >=4.17.21", '', self::patchedAdvisory()),
                self::summary(1, 0, 0, 1, 0),
            ),
            Message::AUDIT_DENO_ADVISORY_LINE_COUNT_INVALID->getMessage('advisory 1', 5, 7),
        ];
        yield 'report above the safety limit' => [
            str_repeat(' ', 16 * 1024 * 1024 + 1),
            Message::AUDIT_REPORT_SIZE_LIMIT_EXCEEDED->getMessage(),
        ];
        yield 'severity count mismatch' => [
            self::report(self::advisory(), self::summary(1, 0, 1, 0, 0)),
            Message::AUDIT_DENO_SUMMARY_SEVERITY_MISMATCH->getMessage(1, 'moderate', 0),
        ];
        yield 'singular summary' => [
            self::report(
                self::advisory(),
                "Found 1 vulnerability\nSeverity: 0 low, 0 moderate, 1 high, 0 critical",
            ),
            Message::AUDIT_DENO_SUMMARY_UNRECOGNIZED->getMessage(),
        ];
        yield 'summary before an advisory' => [
            self::report(self::summary(1, 0, 0, 1, 0), self::advisory()),
            Message::AUDIT_DENO_SUMMARY_UNRECOGNIZED->getMessage(),
        ];
        yield 'summary only' => [
            self::report(self::summary(0, 0, 0, 0, 0)),
            Message::AUDIT_DENO_REPORT_NO_ADVISORY->getMessage(),
        ];
        yield 'summary with leading text' => [
            self::report(self::advisory(), 'Total: ' . self::summary(1, 0, 0, 1, 0)),
            Message::AUDIT_DENO_SUMMARY_UNRECOGNIZED->getMessage(),
        ];
        yield 'summary with trailing text' => [
            self::report(self::advisory(), self::summary(1, 0, 0, 1, 0) . ' found'),
            Message::AUDIT_DENO_SUMMARY_UNRECOGNIZED->getMessage(),
        ];
        yield 'total count mismatch' => [
            self::report(self::advisory(), self::summary(2, 0, 0, 1, 0)),
            Message::AUDIT_DENO_SUMMARY_TOTAL_MISMATCH->getMessage(2, 1),
        ];
        yield 'truncated report' => [
            self::report(self::advisory(), 'Found 1 vulnerabilities'),
            Message::AUDIT_DENO_SUMMARY_UNRECOGNIZED->getMessage(),
        ];
        yield 'whitespace only output' => [" \r\n\t\n", Message::AUDIT_DENO_REPORT_EMPTY->getMessage()];
    }

    /**
     * @return iterable<string, array{string, Severity}>
     */
    public static function singleAdvisoryReports(): iterable
    {
        yield 'critical severity' => [
            self::report(self::advisory('critical'), self::summary(1, 0, 0, 0, 1)),
            Severity::CRITICAL,
        ];
        yield 'high severity' => [
            self::report(self::advisory(), self::summary(1, 0, 0, 1, 0)),
            Severity::HIGH,
        ];
        yield 'high severity with a patched range' => [
            self::report(self::patchedAdvisory(), self::summary(1, 0, 0, 1, 0)),
            Severity::HIGH,
        ];
        yield 'low severity' => [
            self::report(self::advisory('low'), self::summary(1, 1, 0, 0, 0)),
            Severity::LOW,
        ];
        yield 'moderate severity' => [
            self::report(self::advisory('moderate'), self::summary(1, 0, 1, 0, 0)),
            Severity::MODERATE,
        ];
    }

    private static function advisory(
        string $severity = 'high',
        string $info = 'https://github.com/advisories/GHSA-35jh-r3h4-6jhm',
    ): string {
        return "╭ Command Injection in lodash\n"
            . "│ Severity:   {$severity}\n"
            . "│ Package:    lodash\n"
            . "│ Vulnerable: <4.17.21\n"
            . "╰ Info:       {$info}";
    }

    private static function patchedAdvisory(string $actions = 'update lodash to >=4.17.21'): string
    {
        return "╭ Command Injection in lodash\n"
            . "│ Severity:   high\n"
            . "│ Package:    lodash\n"
            . "│ Vulnerable: <4.17.21\n"
            . "│ Patched:    >=4.17.21\n"
            . "│ Info:       https://github.com/advisories/GHSA-35jh-r3h4-6jhm\n"
            . "╰ Actions:    {$actions}";
    }

    private static function report(string ...$blocks): string
    {
        return implode("\n\n", $blocks) . "\n";
    }

    private static function summary(int $total, int $low, int $moderate, int $high, int $critical): string
    {
        return sprintf(
            "Found %d vulnerabilities\nSeverity: %d low, %d moderate, %d high, %d critical",
            $total,
            $low,
            $moderate,
            $high,
            $critical,
        );
    }
}
