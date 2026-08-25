<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Foxy\Audit\{
    AuditFinding,
    AuditReport,
    CveEnricher,
    CveResolution,
    CveResolverInterface,
    CveStatus,
    Severity,
};
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CveEnricherTest extends TestCase
{
    public function testEnricherCachesResolutionForRepeatedGhsa(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);
        $resolver
            ->expects(self::once())
            ->method('resolve')
            ->with('GHSA-35jh-r3h4-6jhm')
            ->willReturn(new CveResolution(['CVE-2021-23337'], CveStatus::RESOLVED));
        $report = new AuditReport(
            'npm',
            [
                $this->finding('lodash', 'GHSA-35jh-r3h4-6jhm'),
                $this->finding('dependent-package', 'GHSA-35jh-r3h4-6jhm'),
            ],
        );

        $enriched = (new CveEnricher($resolver))->enrich(
            $report,
            static fn(string $warning) => self::fail($warning),
        );

        self::assertSame(['CVE-2021-23337'], $enriched->findings[0]->cves);
        self::assertSame(CveStatus::RESOLVED, $enriched->findings[0]->cveStatus);
        self::assertSame(['CVE-2021-23337'], $enriched->findings[1]->cves);
        self::assertSame(CveStatus::RESOLVED, $enriched->findings[1]->cveStatus);
    }

    public function testEnricherDoesNotRequestExistingCves(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');
        $finding = new AuditFinding(
            'lodash',
            Severity::HIGH,
            '1106913',
            '1106913',
            'Prototype pollution',
            '<4.17.21',
            cves: ['CVE-2021-23337'],
        );

        $enriched = (new CveEnricher($resolver))->enrich(
            new AuditReport('pnpm', [$finding]),
            static fn(string $warning) => self::fail($warning),
        );

        self::assertSame(['CVE-2021-23337'], $enriched->findings[0]->cves);
        self::assertSame(CveStatus::RESOLVED, $enriched->findings[0]->cveStatus);
    }

    public function testEnricherMarksNativeAdvisoryAsUnavailableWithoutNetworkRequest(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');

        $enriched = (new CveEnricher($resolver))->enrich(
            new AuditReport('bun', [$this->finding('example-package', '1107000')]),
            static fn(string $warning) => self::fail($warning),
        );

        self::assertSame(CveStatus::UNAVAILABLE, $enriched->findings[0]->cveStatus);
    }

    public function testEnricherMarksSuccessfulResolutionWithoutCve(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);
        $resolver
            ->expects(self::once())
            ->method('resolve')
            ->willReturn(new CveResolution([], CveStatus::NONE_ASSIGNED));

        $enriched = (new CveEnricher($resolver))->enrich(
            new AuditReport('bun', [$this->finding('lodash', 'GHSA-35jh-r3h4-6jhm')]),
            static fn(string $warning) => self::fail($warning),
        );

        self::assertSame([], $enriched->findings[0]->cves);
        self::assertSame(CveStatus::NONE_ASSIGNED, $enriched->findings[0]->cveStatus);
    }

    public function testEnricherWarnsOnceAndPreservesFindingsWhenResolutionFails(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);
        $resolver
            ->expects(self::once())
            ->method('resolve')
            ->willThrowException(new RuntimeException('rate limited'));
        $warnings = [];
        $report = new AuditReport(
            'yarn',
            [
                $this->finding('lodash', 'GHSA-35jh-r3h4-6jhm'),
                $this->finding('dependent-package', 'GHSA-35jh-r3h4-6jhm'),
            ],
        );

        $enriched = (new CveEnricher($resolver))->enrich(
            $report,
            static function (string $warning) use (&$warnings): void {
                $warnings[] = $warning;
            },
        );

        self::assertCount(2, $enriched->findings);
        self::assertSame(CveStatus::UNAVAILABLE, $enriched->findings[0]->cveStatus);
        self::assertSame(CveStatus::UNAVAILABLE, $enriched->findings[1]->cveStatus);
        self::assertSame(
            ['Unable to resolve CVE identifiers for GHSA-35jh-r3h4-6jhm: rate limited'],
            $warnings,
        );
    }

    private function finding(string $package, string $advisoryId): AuditFinding
    {
        return new AuditFinding(
            $package,
            Severity::HIGH,
            $advisoryId,
            '1106913',
            'Prototype pollution',
            '<4.17.21',
        );
    }
}
