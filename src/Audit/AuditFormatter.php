<?php

declare(strict_types=1);

namespace Foxy\Audit;

use JsonException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

use function array_map;
use function count;
use function implode;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

final readonly class AuditFormatter
{
    /**
     * @throws JsonException
     */
    public function write(
        AuditReport $report,
        Severity $minimumSeverity,
        AuditFormat $format,
        OutputInterface $output,
    ): void {
        match ($format) {
            AuditFormat::TABLE => $this->writeTable($report, $output),
            AuditFormat::PLAIN => $this->writePlain($report, $output),
            AuditFormat::JSON => $this->writeJson($report, $minimumSeverity, $output),
            AuditFormat::SUMMARY => $this->writeSummary($report, $output),
        };
    }

    private function formatCves(AuditFinding $finding): string
    {
        return match ($finding->cveStatus) {
            CveStatus::RESOLVED => implode(', ', $finding->cves),
            CveStatus::NONE_ASSIGNED => 'None assigned',
            CveStatus::UNAVAILABLE => 'Unavailable',
            CveStatus::NOT_REQUESTED => 'Not requested',
        };
    }

    /**
     * @throws JsonException
     */
    private function writeJson(AuditReport $report, Severity $minimumSeverity, OutputInterface $output): void
    {
        $advisories = array_map(
            static fn(AuditFinding $finding): array => [
                'package' => $finding->package,
                'severity' => $finding->severity->value,
                'advisory_id' => $finding->advisoryId,
                'source_id' => $finding->sourceId,
                'cves' => $finding->cves,
                'cve_status' => $finding->cveStatus->value,
                'title' => $finding->title,
                'url' => $finding->url,
                'vulnerable_versions' => $finding->vulnerableVersions,
                'affected_versions' => $finding->affectedVersions,
                'dependency_paths' => $finding->dependencyPaths,
            ],
            $report->findings,
        );
        $document = [
            'schema_version' => 1,
            'manager' => $report->manager,
            'audit_level' => $minimumSeverity->value,
            'affected' => $report->hasFindingAtLeast($minimumSeverity),
            'advisories' => $advisories,
            'summary' => [
                'total' => count($report->findings),
                'packages' => $report->countPackages(),
                'severity' => $report->countSeverities(),
            ],
        ];

        $output->writeln(
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            OutputInterface::OUTPUT_RAW,
        );
    }

    private function writePlain(AuditReport $report, OutputInterface $output): void
    {
        if ([] === $report->findings) {
            $output->writeln('No known frontend vulnerabilities found.', OutputInterface::OUTPUT_RAW);

            return;
        }

        foreach ($report->findings as $finding) {
            $fields = [
                $finding->severity->value,
                $finding->package,
                $finding->advisoryId,
                $this->formatCves($finding),
                $finding->vulnerableVersions,
                $finding->title,
            ];

            if (null !== $finding->url) {
                $fields[] = $finding->url;
            }

            $output->writeln(implode(' | ', $fields), OutputInterface::OUTPUT_RAW);
        }

        $this->writeSummary($report, $output);
    }

    private function writeSummary(AuditReport $report, OutputInterface $output): void
    {
        $total = count($report->findings);

        if (0 === $total) {
            $output->writeln('No known frontend vulnerabilities found.', OutputInterface::OUTPUT_RAW);

            return;
        }

        $severity = [];

        foreach ($report->countSeverities() as $name => $count) {
            if ($count > 0) {
                $severity[] = sprintf('%s: %d', $name, $count);
            }
        }

        $packages = $report->countPackages();

        $output->writeln(
            sprintf(
                '%d %s affecting %d %s (%s).',
                $total,
                1 === $total ? 'advisory' : 'advisories',
                $packages,
                1 === $packages ? 'package' : 'packages',
                implode(', ', $severity),
            ),
            OutputInterface::OUTPUT_RAW,
        );
    }

    private function writeTable(AuditReport $report, OutputInterface $output): void
    {
        if ([] === $report->findings) {
            $output->writeln('<info>No known frontend vulnerabilities found.</info>');

            return;
        }

        $rows = [];

        foreach ($report->findings as $finding) {
            $advisory = $finding->advisoryId;

            if (null !== $finding->url) {
                $advisory .= "\n{$finding->url}";
            }

            $rows[] = array_map(
                OutputFormatter::escape(...),
                [
                    $finding->severity->value,
                    $finding->package,
                    $advisory,
                    $this->formatCves($finding),
                    $finding->vulnerableVersions,
                    $finding->title,
                ],
            );
        }

        (new Table($output))
            ->setHeaders(['Severity', 'Package', 'Advisory', 'CVE', 'Vulnerable versions', 'Title'])
            ->setRows($rows)
            ->render();

        $this->writeSummary($report, $output);
    }
}
