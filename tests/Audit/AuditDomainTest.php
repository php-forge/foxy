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

        self::assertNotSame(
            $finding,
            $resolved,
            'Resolution must return a new finding.',
        );
        self::assertSame(
            [],
            $finding->cves,
            'Original CVE list must remain empty.',
        );
        self::assertSame(
            CveStatus::NOT_REQUESTED,
            $finding->cveStatus,
            'Original resolution status must remain unchanged.',
        );
        self::assertSame(
            ['CVE-2021-23337'],
            $resolved->cves,
            'Resolved CVE identifiers must be recorded.',
        );
        self::assertSame(
            CveStatus::RESOLVED,
            $resolved->cveStatus,
            'Resolved status must be recorded.',
        );
        self::assertSame(
            $finding->affectedVersions,
            $resolved->affectedVersions,
            'Affected versions must be preserved.',
        );
        self::assertSame(
            $finding->dependencyPaths,
            $resolved->dependencyPaths,
            'Dependency paths must be preserved.',
        );
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

        self::assertSame(
            2,
            $report->countPackages(),
            'Package count must include each distinct package once.',
        );
        self::assertSame(
            ['critical' => 0, 'high' => 1, 'moderate' => 0, 'low' => 1, 'info' => 1],
            $report->countSeverities(),
            'Severity counts must match the findings.',
        );
        self::assertTrue(
            $report->hasFindingAtLeast(Severity::HIGH),
            'Threshold must include findings at the requested severity.',
        );
        self::assertFalse(
            $report->hasFindingAtLeast(Severity::CRITICAL),
            'Threshold must exclude findings below the requested severity.',
        );

        $replacement = $report->withFindings([$report->findings[0]]);

        self::assertNotSame(
            $report,
            $replacement,
            'Replacement must return a new report.',
        );
        self::assertCount(
            1,
            $replacement->findings,
            'Replacement must contain exactly one finding.',
        );
        self::assertSame(
            'GHSA-35jh-r3h4-6jhm',
            $replacement->findings[0]->advisoryId,
            'Replacement finding must be retained.',
        );
        self::assertSame(
            'manager diagnostics',
            $replacement->diagnostics,
            'Report diagnostics must be preserved.',
        );
    }

    public function testAuditRequestUsesSafeDefaults(): void
    {
        $request = new AuditRequest();

        self::assertSame(
            Severity::LOW,
            $request->minimumSeverity,
            'Default minimum severity must be `LOW`.',
        );
        self::assertFalse(
            $request->noDev,
            'Development dependencies must be included by default.',
        );
    }

    public function testSeverityUsesStableThresholdOrder(): void
    {
        self::assertSame(
            0,
            Severity::INFO->weight(),
            'Info must have the lowest threshold weight.',
        );
        self::assertSame(
            1,
            Severity::LOW->weight(),
            'Low must follow info in threshold order.',
        );
        self::assertSame(
            2,
            Severity::MODERATE->weight(),
            'Moderate must follow low in threshold order.',
        );
        self::assertSame(
            3,
            Severity::HIGH->weight(),
            'High must follow moderate in threshold order.',
        );
        self::assertSame(
            4,
            Severity::CRITICAL->weight(),
            'Critical must have the highest threshold weight.',
        );
        self::assertTrue(
            Severity::CRITICAL->isAtLeast(Severity::CRITICAL),
            'Threshold must include an equal severity.',
        );
        self::assertTrue(
            Severity::HIGH->isAtLeast(Severity::LOW),
            'Threshold must include higher severities.',
        );
        self::assertFalse(
            Severity::INFO->isAtLeast(Severity::LOW),
            'Threshold must exclude lower severities.',
        );
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
