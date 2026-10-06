<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

use Foxy\Audit\{AuditFinding, AuditParserInterface, Severity};

use function array_keys;
use function array_pop;
use function count;
use function explode;
use function preg_match;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

final class DenoAuditParser extends AbstractAuditParser implements AuditParserInterface
{
    /**
     * Line prefixes that open every advisory block, keyed by field, in the order printed by `deno audit`.
     */
    private const array ADVISORY_HEADER_PREFIXES = [
        'title' => "\u{256D} ",
        'severity' => "\u{2502} Severity:   ",
        'package' => "\u{2502} Package:    ",
        'vulnerable' => "\u{2502} Vulnerable: ",
    ];

    /**
     * Line prefixes of an advisory block without a patched range, which closes on the `Info` line.
     */
    private const array ADVISORY_LINE_PREFIXES = [
        ...self::ADVISORY_HEADER_PREFIXES,
        'info' => "\u{2570} Info:       ",
    ];

    private const string CLEAN_REPORT = 'No known vulnerabilities found';

    /**
     * Line prefixes of an advisory block with a patched range, which adds the `Patched` and closing `Actions` lines.
     */
    private const array PATCHED_ADVISORY_LINE_PREFIXES = [
        ...self::ADVISORY_HEADER_PREFIXES,
        'patched' => "\u{2502} Patched:    ",
        'info' => "\u{2502} Info:       ",
        'actions' => "\u{2570} Actions:    ",
    ];

    public function parse(string $output): array
    {
        $this->assertOutputSize($output);
        $output = trim(str_replace(["\r\n", "\r"], "\n", $output));

        if ($output === '') {
            throw $this->malformed('the report is empty');
        }

        if ($output === self::CLEAN_REPORT) {
            return [];
        }

        $blocks = explode("\n\n", $output);

        $summary = $this->parseSummary(array_pop($blocks));

        if ($blocks === []) {
            throw $this->malformed('the report does not contain any advisory');
        }

        $findings = [];
        $counts = ['low' => 0, 'moderate' => 0, 'high' => 0, 'critical' => 0];

        foreach ($blocks as $index => $block) {
            $finding = $this->parseAdvisory($block, $index + 1);
            $findings[] = $finding;

            ++$counts[$finding->severity->value];
        }

        if ($summary['total'] !== count($findings)) {
            throw $this->malformed(
                sprintf(
                    'the summary reports %d vulnerabilities but the report contains %d advisories',
                    $summary['total'],
                    count($findings),
                ),
            );
        }

        foreach ($counts as $severity => $count) {
            if ($summary[$severity] !== $count) {
                throw $this->malformed(
                    sprintf(
                        'the summary reports %d %s vulnerabilities but the report contains %d',
                        $summary[$severity],
                        $severity,
                        $count,
                    ),
                );
            }
        }

        return $findings;
    }

    protected function getManagerName(): string
    {
        return 'deno';
    }

    private function parseAdvisory(string $block, int $index): AuditFinding
    {
        $context = sprintf('advisory %d', $index);
        $lines = explode("\n", $block);

        $prefixes = match (count($lines)) {
            count(self::ADVISORY_LINE_PREFIXES) => self::ADVISORY_LINE_PREFIXES,
            count(self::PATCHED_ADVISORY_LINE_PREFIXES) => self::PATCHED_ADVISORY_LINE_PREFIXES,
            default => throw $this->malformed(
                sprintf(
                    '%s must contain %d or %d lines',
                    $context,
                    count(self::ADVISORY_LINE_PREFIXES),
                    count(self::PATCHED_ADVISORY_LINE_PREFIXES),
                ),
            ),
        };

        $values = [];

        foreach (array_keys($prefixes) as $position => $field) {
            $line = $lines[$position];
            $value = substr($line, strlen($prefixes[$field]));

            if (
                !str_starts_with($line, $prefixes[$field])
                || $value === ''
                || $value !== $this->sanitizeString($value)
            ) {
                throw $this->malformed(sprintf('%s line %d is not recognized', $context, $position + 1));
            }

            $values[$field] = $value;
        }

        $patchedVersions = $values['patched'] ?? null;

        if ($patchedVersions !== null && $values['actions'] !== "update {$values['package']} to {$patchedVersions}") {
            throw $this->malformed(sprintf('%s actions must update the package to its patched versions', $context));
        }

        $severity = $this->getSeverity($values['severity'], $context);

        if ($severity === Severity::INFO) {
            throw $this->malformed(sprintf('%s has an unsupported severity', $context));
        }

        return new AuditFinding(
            $values['package'],
            $severity,
            $this->getGhsaId($values['info']) ?? $values['info'],
            null,
            $values['title'],
            $values['vulnerable'],
            $values['info'],
        );
    }

    /**
     * @return array{total: int, low: int, moderate: int, high: int, critical: int}
     */
    private function parseSummary(string $summary): array
    {
        if (
            1 !== preg_match(
                '/^Found (\d+) vulnerabilities\nSeverity: (\d+) low, (\d+) moderate, (\d+) high, (\d+) critical$/',
                $summary,
                $matches,
            )
        ) {
            throw $this->malformed('the report does not end with a recognized summary');
        }

        return [
            'total' => (int) $matches[1],
            'low' => (int) $matches[2],
            'moderate' => (int) $matches[3],
            'high' => (int) $matches[4],
            'critical' => (int) $matches[5],
        ];
    }
}
