<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

use Foxy\Audit\{AuditFinding, AuditParserInterface, Severity};

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
     * Line prefixes of an advisory block, in the order printed by `deno audit`.
     */
    private const array ADVISORY_LINE_PREFIXES = [
        "\u{256D} ",
        "\u{2502} Severity:   ",
        "\u{2502} Package:    ",
        "\u{2502} Vulnerable: ",
        "\u{2570} Info:       ",
    ];

    private const string CLEAN_REPORT = 'No known vulnerabilities found';

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

        if (count($lines) !== count(self::ADVISORY_LINE_PREFIXES)) {
            throw $this->malformed(
                sprintf('%s must contain exactly %d lines', $context, count(self::ADVISORY_LINE_PREFIXES)),
            );
        }

        $values = [];

        foreach (self::ADVISORY_LINE_PREFIXES as $position => $prefix) {
            $line = $lines[$position];
            $value = substr($line, strlen($prefix));

            if (!str_starts_with($line, $prefix) || $value === '' || $value !== $this->sanitizeString($value)) {
                throw $this->malformed(sprintf('%s line %d is not recognized', $context, $position + 1));
            }

            $values[] = $value;
        }

        [$title, $severity, $package, $vulnerableVersions, $url] = $values;

        $severity = $this->getSeverity($severity, $context);

        if ($severity === Severity::INFO) {
            throw $this->malformed(sprintf('%s has an unsupported severity', $context));
        }

        return new AuditFinding(
            $package,
            $severity,
            $this->getGhsaId($url) ?? $url,
            null,
            $title,
            $vulnerableVersions,
            $url,
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
