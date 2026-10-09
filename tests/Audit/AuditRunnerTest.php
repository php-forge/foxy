<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Foxy\Asset\AssetManagerInterface;
use Foxy\Audit\{AuditProcessResult, AuditRequest, AuditRunner, AuditableAssetManagerInterface, CveStatus, Severity};
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Tests\Provider\AuditRunnerProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function array_reverse;
use function explode;
use function implode;
use function json_decode;
use function json_encode;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Unit tests for {@see AuditRunner} exit code handling, manager diagnostics, and finding deduplication.
 *
 * {@see AuditRunnerProvider} for test case data providers.
 */
final class AuditRunnerTest extends TestCase
{
    use AuditFixture;

    public function testRunnerAcceptsDenoReportWithExitOne(): void
    {
        $manager = $this->manager('deno', new AuditProcessResult(1, self::fixture('deno-populated.txt'), ''));

        $report = (new AuditRunner($manager))->audit(new AuditRequest());

        self::assertSame(
            'deno',
            $report->manager,
            'The report manager should match the expected value',
        );
        self::assertSame(
            '',
            $report->diagnostics,
            'The report diagnostics should be empty',
        );
        self::assertCount(
            8,
            $report->findings,
            'The report should contain every advisory',
        );
        self::assertSame(
            Severity::CRITICAL,
            $report->findings[0]->severity,
            'The findings should start with the most severe advisory',
        );
        self::assertSame(
            'minimist',
            $report->findings[0]->package,
            'The most severe finding should belong to the expected package',
        );
    }

    public function testRunnerAcceptsExitOneWithValidFindingsAndForwardsNoDev(): void
    {
        $manager = $this->manager(
            'npm',
            new AuditProcessResult(1, self::fixture('npm-populated.json'), 'registry warning'),
            true,
        );

        $report = (new AuditRunner($manager))->audit(new AuditRequest(Severity::HIGH, true));

        self::assertSame(
            'npm',
            $report->manager,
            'The report manager should match the expected value',
        );
        self::assertSame(
            'registry warning',
            $report->diagnostics,
            'The manager diagnostics should be kept in the report',
        );
        self::assertCount(
            2,
            $report->findings,
            'The report should contain every advisory',
        );
        self::assertSame(
            Severity::CRITICAL,
            $report->findings[0]->severity,
            'The findings should start with the most severe advisory',
        );
        self::assertSame(
            Severity::HIGH,
            $report->findings[1]->severity,
            'The less severe advisory should follow',
        );
    }

    public function testRunnerAcceptsSuccessfulCleanReport(): void
    {
        $manager = $this->manager('bun', new AuditProcessResult(0, self::fixture('bun-clean.json'), "\n"));

        $report = (new AuditRunner($manager))->audit(new AuditRequest());

        self::assertSame(
            'bun',
            $report->manager,
            'The report manager should match the expected value',
        );
        self::assertSame(
            [],
            $report->findings,
            'The clean report should not contain any finding',
        );
        self::assertSame(
            '',
            $report->diagnostics,
            'The whitespace-only diagnostics should be trimmed to an empty string',
        );
    }

    public function testRunnerDeduplicatesAndMergesEquivalentFindings(): void
    {
        $manager = $this->manager(
            'yarn',
            new AuditProcessResult(1, self::fixture('yarn-duplicates.ndjson'), ''),
        );

        $report = (new AuditRunner($manager))->audit(new AuditRequest());

        self::assertCount(
            1,
            $report->findings,
            'The equivalent findings should be merged into one',
        );
        self::assertSame(
            Severity::HIGH,
            $report->findings[0]->severity,
            'The merged finding should keep the higher severity',
        );
        self::assertSame(
            'Second title',
            $report->findings[0]->title,
            'The merged finding should keep the title of the more severe finding',
        );
        self::assertSame(
            'https://security.example.test/second',
            $report->findings[0]->url,
            'The merged finding should keep the URL of the more severe finding',
        );
        self::assertSame(
            ['1.0.0', '1.1.0'],
            $report->findings[0]->affectedVersions,
            'The affected versions should be merged, unique, and sorted',
        );
        self::assertSame(
            ['parent-a@npm:1.0.0', 'parent-b@npm:2.0.0'],
            $report->findings[0]->dependencyPaths,
            'The dependency paths should be merged and sorted',
        );
    }

    public function testRunnerMarksMergedDuplicateCvesAsResolved(): void
    {
        $data = json_decode(self::fixture('pnpm-populated.json'), true, 512, JSON_THROW_ON_ERROR);

        $duplicate = $data['advisories']['1106913'];
        $duplicate['id'] = 1106914;
        $duplicate['severity'] = 'moderate';
        $duplicate['cves'] = ['CVE-2021-23337'];
        $data['advisories']['1106914'] = $duplicate;
        $data['metadata']['vulnerabilities']['moderate'] = 1;

        $manager = $this->manager(
            'pnpm',
            new AuditProcessResult(1, json_encode($data, JSON_THROW_ON_ERROR), ''),
        );

        $report = (new AuditRunner($manager))->audit(new AuditRequest());

        self::assertCount(
            2,
            $report->findings,
            'The duplicate advisory should be merged into the existing finding',
        );
        self::assertSame(
            Severity::HIGH,
            $report->findings[0]->severity,
            'The merged finding should keep the higher severity',
        );
        self::assertSame(
            ['CVE-2021-23337'],
            $report->findings[0]->cves,
            'The merged finding should carry the CVE identifiers of the duplicate',
        );
        self::assertSame(
            CveStatus::RESOLVED,
            $report->findings[0]->cveStatus,
            'The CVE status should match the expected value',
        );
    }

    public function testRunnerPreservesTheHigherSeverityWhenDuplicateFindingsAreReversed(): void
    {
        $findings = explode("\n", trim(self::fixture('yarn-duplicates.ndjson')));

        $manager = $this->manager(
            'yarn',
            new AuditProcessResult(1, implode("\n", array_reverse($findings)), ''),
        );

        $report = (new AuditRunner($manager))->audit(new AuditRequest());

        self::assertCount(
            1,
            $report->findings,
            'The equivalent findings should be merged into one',
        );
        self::assertSame(
            Severity::HIGH,
            $report->findings[0]->severity,
            'The severity should match the more severe finding',
        );
        self::assertSame(
            'Second title',
            $report->findings[0]->title,
            'The merged finding should keep the title of the more severe finding',
        );
        self::assertSame(
            'https://security.example.test/second',
            $report->findings[0]->url,
            'The merged finding should keep the URL of the more severe finding',
        );
    }

    public function testThrowRuntimeExceptionForMalformedOutputWithManagerDiagnostics(): void
    {
        $manager = $this->manager('npm', new AuditProcessResult(1, '{', 'registry unavailable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_MANAGER_ERROR_APPENDED->getMessage(
                Message::AUDIT_OUTPUT_MALFORMED->getMessage('npm', Message::AUDIT_REPORT_JSON_INVALID->getMessage()),
                'registry unavailable',
            ),
        );

        (new AuditRunner($manager))->audit(new AuditRequest());
    }

    public function testThrowRuntimeExceptionForUnexpectedExitCode(): void
    {
        $manager = $this->manager('pnpm', new AuditProcessResult(2, '', " request failed \n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(2);
        $this->expectExceptionMessage(
            Message::AUDIT_COMMAND_FAILED_WITH_DIAGNOSTICS->getMessage('pnpm', 2, 'request failed'),
        );

        (new AuditRunner($manager))->audit(new AuditRequest());
    }

    public function testThrowRuntimeExceptionWhenAuditCommandFailsWithoutDiagnostics(): void
    {
        $manager = $this->manager('npm', new AuditProcessResult(2, '', ''));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(2);
        $this->expectExceptionMessage(Message::AUDIT_COMMAND_FAILED->getMessage('npm', 2));

        (new AuditRunner($manager))->audit(new AuditRequest());
    }

    #[DataProviderExternal(AuditRunnerProvider::class, 'bunPartialReports')]
    public function testThrowRuntimeExceptionWhenBunProducesDiagnostics(int $exitCode, string $output): void
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
            Message::AUDIT_BUN_PARTIAL_REPORT->getMessage(
                'warn: https://registry.example/ did not answer the audit request (404); skipped @scope/private',
            ),
        );

        (new AuditRunner($manager))->audit(new AuditRequest());
    }

    public function testThrowRuntimeExceptionWhenCleanReportExitsWithOne(): void
    {
        $manager = $this->manager(
            'npm',
            new AuditProcessResult(1, self::fixture('npm-clean.json'), 'network error'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1);
        $this->expectExceptionMessage(
            Message::AUDIT_COMMAND_FAILED_WITH_DIAGNOSTICS->getMessage('npm', 1, 'network error'),
        );

        (new AuditRunner($manager))->audit(new AuditRequest());
    }

    public function testThrowRuntimeExceptionWhenDenoRegistryRequestFails(): void
    {
        $manager = $this->manager(
            'deno',
            new AuditProcessResult(1, '', " error: failed to fetch the audit report \n"),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_MANAGER_ERROR_APPENDED->getMessage(
                Message::AUDIT_OUTPUT_MALFORMED->getMessage('deno', Message::AUDIT_DENO_REPORT_EMPTY->getMessage()),
                'error: failed to fetch the audit report',
            ),
        );

        (new AuditRunner($manager))->audit(new AuditRequest());
    }

    public function testThrowRuntimeExceptionWhenSuccessfulStatusHasBlockingFinding(): void
    {
        $manager = $this->manager(
            'bun',
            new AuditProcessResult(0, self::fixture('bun-populated.json'), ''),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_SUCCESS_STATUS_WITH_FINDINGS->getMessage('bun'),
        );

        (new AuditRunner($manager))->audit(new AuditRequest());
    }

    private function manager(
        string $name,
        AuditProcessResult $result,
        bool $expectedNoDev = false,
    ): AssetManagerInterface&AuditableAssetManagerInterface&MockObject {
        /** @var AssetManagerInterface&AuditableAssetManagerInterface&MockObject $manager */
        $manager = $this->createMockForIntersectionOfInterfaces(
            [
                AssetManagerInterface::class,
                AuditableAssetManagerInterface::class,
            ],
        );

        $manager
            ->expects(self::once())
            ->method('getName')
            ->willReturn($name);
        $manager
            ->expects(self::once())
            ->method('audit')
            ->with($expectedNoDev)
            ->willReturn($result);

        return $manager;
    }
}
