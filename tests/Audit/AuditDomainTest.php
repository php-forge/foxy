<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Foxy\Audit\{AuditFinding, AuditReport, AuditRequest, CveStatus, Severity};
use PHPUnit\Framework\TestCase;

final class AuditDomainTest extends TestCase
{
    public function testAuditFindingCreatesCopyWithCveResolution(): void
    {
        $finding = new AuditFinding(
            'lodash',
            Severity::HIGH,
            'GHSA-35jh-r3h4-6jhm',
            '1106913',
            'Prototype pollution',
            '<4.17.21',
            'https://github.com/advisories/GHSA-35jh-r3h4-6jhm',
            affectedVersions: ['4.17.20'],
            dependencyPaths: ['project>lodash'],
        );

        $resolved = $finding->withCveResolution(['CVE-2021-23337'], CveStatus::RESOLVED);

        self::assertNotSame($finding, $resolved);
        self::assertSame([], $finding->cves);
        self::assertSame(CveStatus::NOT_REQUESTED, $finding->cveStatus);
        self::assertSame(['CVE-2021-23337'], $resolved->cves);
        self::assertSame(CveStatus::RESOLVED, $resolved->cveStatus);
        self::assertSame($finding->affectedVersions, $resolved->affectedVersions);
        self::assertSame($finding->dependencyPaths, $resolved->dependencyPaths);
    }

    public function testAuditReportCountsAndReplacesFindings(): void
    {
        $report = new AuditReport(
            'npm',
            [
                $this->finding('lodash', Severity::HIGH, 'GHSA-35jh-r3h4-6jhm'),
                $this->finding('lodash', Severity::LOW, 'GHSA-jf85-cpcp-j695'),
                $this->finding('example-package', Severity::INFO, '1107000'),
            ],
            'manager diagnostics',
        );

        self::assertSame(2, $report->countPackages());
        self::assertSame(
            ['critical' => 0, 'high' => 1, 'moderate' => 0, 'low' => 1, 'info' => 1],
            $report->countSeverities(),
        );
        self::assertTrue($report->hasFindingAtLeast(Severity::HIGH));
        self::assertFalse($report->hasFindingAtLeast(Severity::CRITICAL));

        $replacement = $report->withFindings([$report->findings[0]]);

        self::assertNotSame($report, $replacement);
        self::assertCount(1, $replacement->findings);
        self::assertSame('GHSA-35jh-r3h4-6jhm', $replacement->findings[0]->advisoryId);
        self::assertSame('manager diagnostics', $replacement->diagnostics);
    }

    public function testAuditRequestUsesSafeDefaults(): void
    {
        $request = new AuditRequest();

        self::assertSame(Severity::LOW, $request->minimumSeverity);
        self::assertFalse($request->noDev);
    }

    public function testSeverityUsesStableThresholdOrder(): void
    {
        self::assertSame(0, Severity::INFO->weight());
        self::assertSame(1, Severity::LOW->weight());
        self::assertSame(2, Severity::MODERATE->weight());
        self::assertSame(3, Severity::HIGH->weight());
        self::assertSame(4, Severity::CRITICAL->weight());
        self::assertTrue(Severity::CRITICAL->isAtLeast(Severity::CRITICAL));
        self::assertTrue(Severity::HIGH->isAtLeast(Severity::LOW));
        self::assertFalse(Severity::INFO->isAtLeast(Severity::LOW));
    }

    private function finding(string $package, Severity $severity, string $advisoryId): AuditFinding
    {
        return new AuditFinding(
            $package,
            $severity,
            $advisoryId,
            $advisoryId,
            'Advisory title',
            '<1.0.0',
        );
    }
}
