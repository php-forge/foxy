<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Foxy\Audit\{
    AuditFinding,
    AuditFormat,
    AuditFormatter,
    AuditReport,
    CveStatus,
    Severity,
};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

use function json_decode;
use function str_replace;

use const JSON_THROW_ON_ERROR;

final class AuditFormatterTest extends TestCase
{
    /**
     * Provides CVE resolution states for plain formatter tests.
     *
     * @return array<string, array{CveStatus, list<string>, string}>
     */
    public static function getCveStatusData(): array
    {
        return [
            'resolved' => [CveStatus::RESOLVED, ['CVE-2021-23337'], 'CVE-2021-23337'],
            'none assigned' => [CveStatus::NONE_ASSIGNED, [], 'None assigned'],
            'unavailable' => [CveStatus::UNAVAILABLE, [], 'Unavailable'],
            'not requested' => [CveStatus::NOT_REQUESTED, [], 'Not requested'],
        ];
    }

    public function testJsonFormatProvidesStableMachineReadableDocument(): void
    {
        $report = new AuditReport(
            'npm',
            [
                $this->finding(
                    'lodash',
                    Severity::HIGH,
                    'GHSA-35jh-r3h4-6jhm',
                    CveStatus::RESOLVED,
                    ['CVE-2021-23337'],
                    'https://github.com/advisories/GHSA-35jh-r3h4-6jhm',
                ),
                $this->finding('example-package', Severity::INFO, '1107000', CveStatus::UNAVAILABLE),
            ],
        );
        $output = new BufferedOutput();

        (new AuditFormatter())->write($report, Severity::CRITICAL, AuditFormat::JSON, $output);

        self::assertSame(
            [
                'schema_version' => 1,
                'manager' => 'npm',
                'audit_level' => 'critical',
                'affected' => false,
                'advisories' => [
                    [
                        'package' => 'lodash',
                        'severity' => 'high',
                        'advisory_id' => 'GHSA-35jh-r3h4-6jhm',
                        'source_id' => '1106913',
                        'cves' => ['CVE-2021-23337'],
                        'cve_status' => 'resolved',
                        'title' => 'Advisory title',
                        'url' => 'https://github.com/advisories/GHSA-35jh-r3h4-6jhm',
                        'vulnerable_versions' => '<1.0.0',
                        'affected_versions' => ['0.9.0'],
                        'dependency_paths' => ['project>package'],
                    ],
                    [
                        'package' => 'example-package',
                        'severity' => 'info',
                        'advisory_id' => '1107000',
                        'source_id' => '1106913',
                        'cves' => [],
                        'cve_status' => 'unavailable',
                        'title' => 'Advisory title',
                        'url' => null,
                        'vulnerable_versions' => '<1.0.0',
                        'affected_versions' => ['0.9.0'],
                        'dependency_paths' => ['project>package'],
                    ],
                ],
                'summary' => [
                    'total' => 2,
                    'packages' => 2,
                    'severity' => [
                        'critical' => 0,
                        'high' => 1,
                        'moderate' => 0,
                        'low' => 0,
                        'info' => 1,
                    ],
                ],
            ],
            json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    #[DataProvider('getCveStatusData')]
    public function testPlainFormatExplainsCveResolutionStatus(
        CveStatus $status,
        array $cves,
        string $expected,
    ): void {
        $output = new BufferedOutput();
        $report = new AuditReport(
            'npm',
            [$this->finding('lodash', Severity::HIGH, 'GHSA-35jh-r3h4-6jhm', $status, $cves)],
        );

        (new AuditFormatter())->write($report, Severity::LOW, AuditFormat::PLAIN, $output);

        self::assertStringContainsString(' | ' . $expected . ' | ', $output->fetch());
    }

    public function testPlainFormatIncludesAdvisoryDetailsAndSummary(): void
    {
        $report = new AuditReport(
            'pnpm',
            [
                $this->finding(
                    'lodash',
                    Severity::HIGH,
                    'GHSA-35jh-r3h4-6jhm',
                    CveStatus::RESOLVED,
                    ['CVE-2021-23337'],
                    'https://github.com/advisories/GHSA-35jh-r3h4-6jhm',
                ),
            ],
        );
        $output = new BufferedOutput();

        (new AuditFormatter())->write($report, Severity::LOW, AuditFormat::PLAIN, $output);

        self::assertSame(
            "high | lodash | GHSA-35jh-r3h4-6jhm | CVE-2021-23337 | <1.0.0 | Advisory title | https://github.com/advisories/GHSA-35jh-r3h4-6jhm\n"
            . "1 advisory affecting 1 package (high: 1).\n",
            self::normalizeLineEndings($output->fetch()),
        );
    }

    public function testSummaryFormatReportsAdvisoriesPackagesAndSeverities(): void
    {
        $report = new AuditReport(
            'yarn',
            [
                $this->finding('lodash', Severity::CRITICAL, 'GHSA-jf85-cpcp-j695'),
                $this->finding('lodash', Severity::HIGH, 'GHSA-35jh-r3h4-6jhm'),
                $this->finding('example-package', Severity::INFO, '1107000'),
            ],
        );
        $output = new BufferedOutput();

        (new AuditFormatter())->write($report, Severity::LOW, AuditFormat::SUMMARY, $output);

        self::assertSame(
            "3 advisories affecting 2 packages (critical: 1, high: 1, info: 1).\n",
            self::normalizeLineEndings($output->fetch()),
        );
    }

    public function testSummaryFormatReportsCleanAudit(): void
    {
        $output = new BufferedOutput();

        (new AuditFormatter())->write(
            new AuditReport('npm', []),
            Severity::LOW,
            AuditFormat::SUMMARY,
            $output,
        );

        self::assertSame(
            "No known frontend vulnerabilities found.\n",
            self::normalizeLineEndings($output->fetch()),
        );
    }

    public function testTableFormatEscapesAdvisoryMarkup(): void
    {
        $report = new AuditReport(
            'npm',
            [
                new AuditFinding(
                    '<error>package</error>',
                    Severity::HIGH,
                    'GHSA-35jh-r3h4-6jhm',
                    '1106913',
                    '<info>Advisory title</info>',
                    '<1.0.0',
                ),
            ],
        );
        $output = new BufferedOutput();

        (new AuditFormatter())->write($report, Severity::LOW, AuditFormat::TABLE, $output);
        $formatted = $output->fetch();

        self::assertStringContainsString('<error>package</error>', $formatted);
        self::assertStringContainsString('<info>Advisory title</info>', $formatted);
    }

    public function testTableFormatIncludesCveAndSummary(): void
    {
        $report = new AuditReport(
            'bun',
            [
                $this->finding(
                    'lodash',
                    Severity::HIGH,
                    'GHSA-35jh-r3h4-6jhm',
                    CveStatus::RESOLVED,
                    ['CVE-2021-23337'],
                ),
            ],
        );
        $output = new BufferedOutput();

        (new AuditFormatter())->write($report, Severity::LOW, AuditFormat::TABLE, $output);
        $formatted = $output->fetch();

        self::assertStringContainsString('Severity', $formatted);
        self::assertStringContainsString('lodash', $formatted);
        self::assertStringContainsString('GHSA-35jh-r3h4-6jhm', $formatted);
        self::assertStringContainsString('CVE-2021-23337', $formatted);
        self::assertStringContainsString('1 advisory affecting 1 package (high: 1).', $formatted);
    }

    /**
     * @param list<string> $cves
     */
    private function finding(
        string $package,
        Severity $severity,
        string $advisoryId,
        CveStatus $cveStatus = CveStatus::NOT_REQUESTED,
        array $cves = [],
        string|null $url = null,
    ): AuditFinding {
        return new AuditFinding(
            $package,
            $severity,
            $advisoryId,
            '1106913',
            'Advisory title',
            '<1.0.0',
            $url,
            $cves,
            $cveStatus,
            ['0.9.0'],
            ['project>package'],
        );
    }

    private static function normalizeLineEndings(string $output): string
    {
        return str_replace(["\r\n", "\r"], "\n", $output);
    }
}
