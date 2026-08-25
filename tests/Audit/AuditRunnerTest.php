<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Foxy\Asset\AssetManagerInterface;
use Foxy\Audit\{
    AuditProcessResult,
    AuditRequest,
    AuditRunner,
    AuditableAssetManagerInterface,
    Severity,
};
use Foxy\Exception\RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AuditRunnerTest extends TestCase
{
    use AuditFixture;

    public static function bunPartialReports(): array
    {
        return [
            'native status zero and clean body' => [0, self::fixture('bun-clean.json')],
            'native status one and vulnerable body' => [1, self::fixture('bun-populated.json')],
        ];
    }

    public function testRunnerAcceptsExitOneWithValidFindingsAndForwardsNoDev(): void
    {
        $manager = $this->manager(
            'npm',
            new AuditProcessResult(1, self::fixture('npm-populated.json'), 'registry warning'),
            true,
        );

        $report = (new AuditRunner($manager))->audit(new AuditRequest(Severity::HIGH, true));

        self::assertSame('npm', $report->manager);
        self::assertSame('registry warning', $report->diagnostics);
        self::assertCount(2, $report->findings);
        self::assertSame(Severity::CRITICAL, $report->findings[0]->severity);
        self::assertSame(Severity::HIGH, $report->findings[1]->severity);
    }

    public function testRunnerAcceptsSuccessfulCleanReport(): void
    {
        $manager = $this->manager('bun', new AuditProcessResult(0, self::fixture('bun-clean.json'), "\n"));

        $report = (new AuditRunner($manager))->audit(new AuditRequest());

        self::assertSame('bun', $report->manager);
        self::assertSame([], $report->findings);
        self::assertSame('', $report->diagnostics);
    }

    public function testRunnerDeduplicatesAndMergesEquivalentFindings(): void
    {
        $manager = $this->manager(
            'yarn',
            new AuditProcessResult(1, self::fixture('yarn-duplicates.ndjson'), ''),
        );

        $report = (new AuditRunner($manager))->audit(new AuditRequest());

        self::assertCount(1, $report->findings);
        self::assertSame(Severity::HIGH, $report->findings[0]->severity);
        self::assertSame('First title', $report->findings[0]->title);
        self::assertSame(['1.0.0', '1.1.0'], $report->findings[0]->affectedVersions);
        self::assertSame(
            ['parent-a@npm:1.0.0', 'parent-b@npm:2.0.0'],
            $report->findings[0]->dependencyPaths,
        );
    }

    #[DataProvider('bunPartialReports')]
    public function testRunnerRejectsBunPartialReportDiagnostics(int $exitCode, string $output): void
    {
        $manager = $this->manager(
            'bun',
            new AuditProcessResult(
                $exitCode,
                $output,
                'warn: https://registry.example/ did not answer the audit request (404); skipped @scope/private',
            ),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The bun audit command produced diagnostics and may have returned a partial report. '
            . 'warn: https://registry.example/ did not answer the audit request (404); skipped @scope/private',
        );

        (new AuditRunner($manager))->audit(new AuditRequest());
    }

    public function testRunnerRejectsCleanReportWithExitOne(): void
    {
        $manager = $this->manager(
            'npm',
            new AuditProcessResult(1, self::fixture('npm-clean.json'), 'network error'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1);
        $this->expectExceptionMessage('The npm audit command failed with status code 1. network error');

        (new AuditRunner($manager))->audit(new AuditRequest());
    }

    public function testRunnerRejectsMalformedOutputAndIncludesManagerDiagnostics(): void
    {
        $manager = $this->manager('npm', new AuditProcessResult(1, '{', 'registry unavailable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The npm audit output is malformed: invalid JSON. Manager error: registry unavailable');

        (new AuditRunner($manager))->audit(new AuditRequest());
    }

    public function testRunnerRejectsSuccessfulStatusWithBlockingFinding(): void
    {
        $manager = $this->manager(
            'bun',
            new AuditProcessResult(0, self::fixture('bun-populated.json'), ''),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The bun audit report contains vulnerabilities but the manager returned a successful status.',
        );

        (new AuditRunner($manager))->audit(new AuditRequest());
    }

    public function testRunnerRejectsUnexpectedExitCode(): void
    {
        $manager = $this->manager('pnpm', new AuditProcessResult(2, '', " request failed \n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(2);
        $this->expectExceptionMessage('The pnpm audit command failed with status code 2. request failed');

        (new AuditRunner($manager))->audit(new AuditRequest());
    }

    private function manager(
        string $name,
        AuditProcessResult $result,
        bool $expectedNoDev = false,
    ): AssetManagerInterface&AuditableAssetManagerInterface&MockObject {
        /** @var AssetManagerInterface&AuditableAssetManagerInterface&MockObject $manager */
        $manager = $this->createMockForIntersectionOfInterfaces([
            AssetManagerInterface::class,
            AuditableAssetManagerInterface::class,
        ]);
        $manager->expects(self::once())->method('getName')->willReturn($name);
        $manager->expects(self::once())->method('audit')->with($expectedNoDev)->willReturn($result);

        return $manager;
    }
}
