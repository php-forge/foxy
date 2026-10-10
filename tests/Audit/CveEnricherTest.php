<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Foxy\Audit\{AuditFinding, AuditReport, CveEnricher, CveResolution, CveResolverInterface, CveStatus, Severity};
use Foxy\Exception\Message;
use Foxy\Tests\Provider\CveEnricherProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;

/**
 * Unit tests for {@see CveEnricher} GHSA to CVE enrichment of audit findings.
 *
 * {@see CveEnricherProvider} for test case data providers.
 */
final class CveEnricherTest extends TestCase
{
    public function testEnricherCachesResolutionForRepeatedGhsa(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::once())
            ->method('resolveMany')
            ->with(['GHSA-35jh-r3h4-6jhm'])
            ->willReturn(['GHSA-35jh-r3h4-6jhm' => new CveResolution(['CVE-2021-23337'], CveStatus::RESOLVED)]);

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

        self::assertSame(
            ['CVE-2021-23337'],
            $enriched->findings[0]->cves,
            'Resolved CVE identifiers must be applied to the first finding.',
        );
        self::assertSame(
            CveStatus::RESOLVED,
            $enriched->findings[0]->cveStatus,
            'First finding must record a resolved status.',
        );
        self::assertSame(
            ['CVE-2021-23337'],
            $enriched->findings[1]->cves,
            'Cached CVE identifiers must be applied to the repeated finding.',
        );
        self::assertSame(
            CveStatus::RESOLVED,
            $enriched->findings[1]->cveStatus,
            'Repeated finding must record a resolved status.',
        );
    }

    public function testEnricherDoesNotRequestExistingCves(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::once())
            ->method('resolveMany')
            ->with(['GHSA-35jh-r3h4-6jhm'])
            ->willReturn(['GHSA-35jh-r3h4-6jhm' => new CveResolution([], CveStatus::NONE_ASSIGNED)]);

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
            new AuditReport(
                'pnpm',
                [$finding, $this->finding('example-package', 'GHSA-35jh-r3h4-6jhm')],
            ),
            static fn(string $warning) => self::fail($warning),
        );

        self::assertCount(
            2,
            $enriched->findings,
            'Both findings must remain in the report.',
        );
        self::assertSame(
            ['CVE-2021-23337'],
            $enriched->findings[0]->cves,
            'Existing CVEs must be preserved.',
        );
        self::assertSame(
            CveStatus::RESOLVED,
            $enriched->findings[0]->cveStatus,
            'Finding with existing CVEs must be marked resolved.',
        );
        self::assertSame(
            CveStatus::NONE_ASSIGNED,
            $enriched->findings[1]->cveStatus,
            'Unmatched advisory must record that no CVE is assigned.',
        );
    }

    public function testEnricherIsolatesFailedLookupInMixedBatch(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::once())
            ->method('resolveMany')
            ->with(['GHSA-35jh-r3h4-6jhm', 'GHSA-aaaa-bbbb-cccc'])
            ->willReturn(
                [
                    'GHSA-aaaa-bbbb-cccc' => new RuntimeException('not found'),
                    'GHSA-35jh-r3h4-6jhm' => new CveResolution(['CVE-2021-23337'], CveStatus::RESOLVED),
                ],
            );

        $warnings = [];

        $enriched = (new CveEnricher($resolver))->enrich(
            new AuditReport(
                'npm',
                [
                    $this->finding('lodash', 'GHSA-35jh-r3h4-6jhm'),
                    $this->finding('example-package', 'GHSA-aaaa-bbbb-cccc'),
                    $this->finding('dependent-package', 'GHSA-35jh-r3h4-6jhm'),
                ],
            ),
            static function (string $warning) use (&$warnings): void {
                $warnings[] = $warning;
            },
        );

        self::assertSame(
            ['lodash', 'example-package', 'dependent-package'],
            array_map(static fn(AuditFinding $finding): string => $finding->package, $enriched->findings),
            'Findings must keep the report order.',
        );
        self::assertSame(
            [CveStatus::RESOLVED, CveStatus::UNAVAILABLE, CveStatus::RESOLVED],
            array_map(static fn(AuditFinding $finding): CveStatus => $finding->cveStatus, $enriched->findings),
            'Only the failed advisory must be marked unavailable.',
        );
        self::assertSame(
            [['CVE-2021-23337'], [], ['CVE-2021-23337']],
            array_map(static fn(AuditFinding $finding): array => $finding->cves, $enriched->findings),
            'Resolved CVE identifiers must reach both findings of the resolved advisory.',
        );
        self::assertSame(
            ['Unable to resolve CVE identifiers for GHSA-aaaa-bbbb-cccc: not found'],
            $warnings,
            'Only the failed advisory must emit a warning.',
        );
    }

    public function testEnricherMarksAdvisoryUnavailableWhenResolverOmitsIt(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::once())
            ->method('resolveMany')
            ->with(['GHSA-aaaa-bbbb-cccc', 'GHSA-35jh-r3h4-6jhm'])
            ->willReturn(['GHSA-35jh-r3h4-6jhm' => new CveResolution(['CVE-2021-23337'], CveStatus::RESOLVED)]);

        $warnings = [];

        $enriched = (new CveEnricher($resolver))->enrich(
            new AuditReport(
                'npm',
                [
                    $this->finding('example-package', 'GHSA-aaaa-bbbb-cccc'),
                    $this->finding('lodash', 'GHSA-35jh-r3h4-6jhm'),
                ],
            ),
            static function (string $warning) use (&$warnings): void {
                $warnings[] = $warning;
            },
        );

        self::assertSame(
            [CveStatus::UNAVAILABLE, CveStatus::RESOLVED],
            array_map(static fn(AuditFinding $finding): CveStatus => $finding->cveStatus, $enriched->findings),
            'Only the omitted advisory must be marked unavailable.',
        );
        self::assertSame(
            [
                'Unable to resolve CVE identifiers for GHSA-aaaa-bbbb-cccc: '
                . Message::AUDIT_CVE_RESOLUTION_MISSING->getMessage('GHSA-aaaa-bbbb-cccc'),
            ],
            $warnings,
            'The omitted advisory must produce one warning.',
        );
    }

    public function testEnricherMarksEveryAdvisoryUnavailableWhenBatchFails(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::once())
            ->method('resolveMany')
            ->with(['GHSA-aaaa-bbbb-cccc', 'GHSA-35jh-r3h4-6jhm'])
            ->willThrowException(new RuntimeException('network down'));

        $warnings = [];

        $enriched = (new CveEnricher($resolver))->enrich(
            new AuditReport(
                'npm',
                [
                    $this->finding('example-package', 'GHSA-aaaa-bbbb-cccc'),
                    $this->finding('lodash', 'GHSA-35jh-r3h4-6jhm'),
                ],
            ),
            static function (string $warning) use (&$warnings): void {
                $warnings[] = $warning;
            },
        );

        self::assertSame(
            [CveStatus::UNAVAILABLE, CveStatus::UNAVAILABLE],
            array_map(static fn(AuditFinding $finding): CveStatus => $finding->cveStatus, $enriched->findings),
            'Every requested advisory must be marked unavailable.',
        );
        self::assertSame(
            [
                'Unable to resolve CVE identifiers for GHSA-aaaa-bbbb-cccc: network down',
                'Unable to resolve CVE identifiers for GHSA-35jh-r3h4-6jhm: network down',
            ],
            $warnings,
            'One warning per requested advisory must be emitted in first-seen order.',
        );
    }

    public function testEnricherMarksNativeAdvisoryAsUnavailableWithoutNetworkRequest(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::never())
            ->method('resolveMany');

        $enriched = (new CveEnricher($resolver))->enrich(
            new AuditReport(
                'bun',
                [
                    $this->finding('example-package', '1107000'),
                    $this->finding('dependent-package', '1107001'),
                ],
            ),
            static fn(string $warning) => self::fail($warning),
        );

        self::assertCount(
            2,
            $enriched->findings,
            'Both native advisories must remain in the report.',
        );
        self::assertSame(
            CveStatus::UNAVAILABLE,
            $enriched->findings[0]->cveStatus,
            'First native advisory must be marked unavailable.',
        );
        self::assertSame(
            CveStatus::UNAVAILABLE,
            $enriched->findings[1]->cveStatus,
            'Second native advisory must be marked unavailable.',
        );
    }

    public function testEnricherMarksSuccessfulResolutionWithoutCve(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::once())
            ->method('resolveMany')
            ->with(['GHSA-35jh-r3h4-6jhm'])
            ->willReturn(['GHSA-35jh-r3h4-6jhm' => new CveResolution([], CveStatus::NONE_ASSIGNED)]);

        $enriched = (new CveEnricher($resolver))->enrich(
            new AuditReport('bun', [$this->finding('lodash', 'GHSA-35jh-r3h4-6jhm')]),
            static fn(string $warning) => self::fail($warning),
        );

        self::assertSame(
            [],
            $enriched->findings[0]->cves,
            'No CVE identifiers must be added.',
        );
        self::assertSame(
            CveStatus::NONE_ASSIGNED,
            $enriched->findings[0]->cveStatus,
            'Resolution must record that no CVE is assigned.',
        );
    }

    #[DataProviderExternal(CveEnricherProvider::class, 'advisoryIdsWithSurroundingText')]
    public function testEnricherRejectsGhsaWithSurroundingText(string $advisoryId): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::never())
            ->method('resolveMany');

        $enriched = (new CveEnricher($resolver))->enrich(
            new AuditReport('npm', [$this->finding('example-package', $advisoryId)]),
            static function (string $warning): never {
                self::fail($warning);
            },
        );

        self::assertSame(
            CveStatus::UNAVAILABLE,
            $enriched->findings[0]->cveStatus,
            'Unrecognized advisory identifier must be marked unavailable.',
        );
    }

    public function testEnricherSharesResolutionForGhsaIdsDifferingInCase(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::once())
            ->method('resolveMany')
            ->with(['GHSA-35jh-r3h4-6jhm'])
            ->willReturn(['GHSA-35jh-r3h4-6jhm' => new CveResolution(['CVE-2021-23337'], CveStatus::RESOLVED)]);

        $report = new AuditReport(
            'npm',
            [
                $this->finding('lodash', 'ghsa-35JH-R3H4-6JHM'),
                $this->finding('dependent-package', 'GHSA-35jh-r3h4-6jhm'),
            ],
        );

        $enriched = (new CveEnricher($resolver))->enrich(
            $report,
            static fn(string $warning) => self::fail($warning),
        );

        self::assertSame(
            ['CVE-2021-23337'],
            $enriched->findings[0]->cves,
            'Mixed-case identifier must receive the resolved CVE identifiers.',
        );
        self::assertSame(
            ['CVE-2021-23337'],
            $enriched->findings[1]->cves,
            'Canonical identifier must reuse the cached resolution.',
        );
        self::assertSame(
            'ghsa-35JH-R3H4-6JHM',
            $enriched->findings[0]->advisoryId,
            'Original advisory identifier must be preserved.',
        );
    }

    public function testEnricherWarnsInFirstSeenOrderWhenSeveralLookupsFail(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::once())
            ->method('resolveMany')
            ->with(['GHSA-aaaa-bbbb-cccc', 'GHSA-35jh-r3h4-6jhm'])
            ->willReturn(
                [
                    'GHSA-35jh-r3h4-6jhm' => new RuntimeException('rate limited'),
                    'GHSA-aaaa-bbbb-cccc' => new RuntimeException('not found'),
                ],
            );

        $warnings = [];

        (new CveEnricher($resolver))->enrich(
            new AuditReport(
                'npm',
                [
                    $this->finding('example-package', 'GHSA-aaaa-bbbb-cccc'),
                    $this->finding('lodash', 'GHSA-35jh-r3h4-6jhm'),
                ],
            ),
            static function (string $warning) use (&$warnings): void {
                $warnings[] = $warning;
            },
        );

        self::assertSame(
            [
                'Unable to resolve CVE identifiers for GHSA-aaaa-bbbb-cccc: not found',
                'Unable to resolve CVE identifiers for GHSA-35jh-r3h4-6jhm: rate limited',
            ],
            $warnings,
            'Warnings must follow first-seen order, not result order.',
        );
    }

    public function testEnricherWarnsOnceAndPreservesFindingsWhenResolutionFails(): void
    {
        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::once())
            ->method('resolveMany')
            ->with(['GHSA-35jh-r3h4-6jhm'])
            ->willReturn(['GHSA-35jh-r3h4-6jhm' => new RuntimeException('rate limited')]);

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

        self::assertCount(
            2,
            $enriched->findings,
            'Resolution failure must preserve both findings.',
        );
        self::assertSame(
            CveStatus::UNAVAILABLE,
            $enriched->findings[0]->cveStatus,
            'First finding must be marked unavailable after resolution fails.',
        );
        self::assertSame(
            CveStatus::UNAVAILABLE,
            $enriched->findings[1]->cveStatus,
            'Repeated finding must be marked unavailable after resolution fails.',
        );
        self::assertSame(
            ['Unable to resolve CVE identifiers for GHSA-35jh-r3h4-6jhm: rate limited'],
            $warnings,
            'Resolution failure must emit one warning.',
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
