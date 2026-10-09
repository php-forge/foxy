<?php

declare(strict_types=1);

namespace Foxy\Tests\Command;

use Composer\Composer;
use Composer\Console\Application;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\{IOInterface, NullIO};
use Foxy\Audit\{
    AuditFinding,
    AuditFormat,
    AuditReport,
    AuditRequest,
    AuditRunnerInterface,
    CveResolution,
    CveResolverInterface,
    CveStatus,
    Severity
};
use Foxy\Command\AuditCommand;
use Foxy\Exception\RuntimeException;
use Foxy\Tests\Provider\AuditCommandProvider;
use JsonException;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Unit tests for {@see AuditCommand} option validation, output formats, and exit status handling.
 *
 * {@see AuditCommandProvider} for test case data providers.
 */
final class AuditCommandTest extends TestCase
{
    #[DataProviderExternal(AuditCommandProvider::class, 'auditFormats')]
    public function testAcceptsEveryDocumentedFormat(string $format): void
    {
        $runner = $this->createMock(AuditRunnerInterface::class);

        $runner
            ->expects(self::once())
            ->method('audit')
            ->willReturn(new AuditReport('npm', []));

        $tester = $this->createTester($runner);

        self::assertSame(
            AuditCommand::STATUS_OK,
            $tester->execute(['--format' => $format]),
            'Supported format must return a successful status.',
        );
        self::assertNotSame(
            '',
            $tester->getDisplay(true),
            'Successful format must produce output.',
        );
    }

    #[DataProviderExternal(AuditCommandProvider::class, 'thresholdStatuses')]
    public function testAuditLevelControlsTheExitStatus(
        Severity $findingSeverity,
        Severity $minimumSeverity,
        int $expectedStatus,
    ): void {
        $runner = $this->createMock(AuditRunnerInterface::class);

        $runner
            ->expects(self::once())
            ->method('audit')
            ->with(
                self::callback(
                    static fn(AuditRequest $request): bool => $minimumSeverity === $request->minimumSeverity
                        && $request->noDev,
                ),
            )
            ->willReturn(new AuditReport('npm', [self::finding($findingSeverity)]));

        $tester = $this->createTester($runner);

        self::assertSame(
            $expectedStatus,
            $tester->execute(
                [
                    '--audit-level' => $minimumSeverity->value,
                    '--format' => AuditFormat::SUMMARY->value,
                    '--no-dev' => true,
                ],
            ),
            'Exit status must match the configured severity threshold.',
        );
        self::assertStringContainsString(
            '1 advisory affecting 1 package',
            $tester->getDisplay(true),
            'Output must include the advisory summary.',
        );
    }

    public function testCommandConfiguration(): void
    {
        $command = new AuditCommand($this->createMock(AuditRunnerInterface::class));

        $definition = $command->getDefinition();

        self::assertSame(
            'foxy:audit',
            $command->getName(),
            'Command name must match its registered name.',
        );
        self::assertSame(
            'Checks frontend dependencies for known security vulnerabilities',
            $command->getDescription(),
            'Command description must explain its purpose.',
        );
        self::assertSame(
            AuditFormat::TABLE->value,
            $definition->getOption('format')->getDefault(),
            'Table must be the default output format.',
        );
        self::assertSame(
            'f',
            $definition->getOption('format')->getShortcut(),
            'Format option must expose the `f` shortcut.',
        );
        self::assertSame(
            Severity::LOW->value,
            $definition->getOption('audit-level')->getDefault(),
            'Low severity must be the default audit threshold.',
        );
        self::assertFalse(
            $definition->getOption('no-dev')->getDefault(),
            'Development dependencies must be included by default.',
        );
        self::assertFalse(
            $definition->getOption('no-cve')->getDefault(),
            'CVE resolution must be enabled by default.',
        );
        self::assertStringContainsString(
            'Exit status 0',
            $command->getHelp(),
            'Help must document the successful exit status.',
        );
        self::assertStringContainsString(
            '1 means at least one advisory',
            $command->getHelp(),
            'Help must document the vulnerable exit status.',
        );
        self::assertStringContainsString(
            '2 means the audit could not be completed reliably',
            $command->getHelp(),
            'Help must document the operational failure status.',
        );
    }

    /**
     * @throws JsonException
     */
    public function testCveResolutionFailureWritesASanitizedWarningAndPreservesTheReport(): void
    {
        $runner = $this->createMock(AuditRunnerInterface::class);

        $runner
            ->expects(self::once())
            ->method('audit')
            ->willReturn(new AuditReport('npm', [self::finding(Severity::HIGH)]));

        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::once())
            ->method('resolve')
            ->with('GHSA-aaaa-bbbb-cccc')
            ->willThrowException(new RuntimeException("rate\nlimited\0now"));

        $io = $this->createMock(IOInterface::class);

        $io
            ->expects(self::once())
            ->method('writeError')
            ->with(
                '<warning>Unable to resolve CVE identifiers for GHSA-aaaa-bbbb-cccc: rate limited now</warning>',
            );

        $tester = $this->createTester($runner, $resolver, $io);

        self::assertSame(
            AuditCommand::STATUS_VULNERABLE,
            $tester->execute(['--format' => AuditFormat::JSON->value]),
            'Existing findings must retain the vulnerable exit status.',
        );

        /** @var array $document */
        $document = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            CveStatus::UNAVAILABLE->value,
            $document['advisories'][0]['cve_status'],
            'Failed CVE resolution must be marked unavailable.',
        );
    }

    public function testDefaultOptionsProduceASuccessfulTableAudit(): void
    {
        $runner = $this->createMock(AuditRunnerInterface::class);

        $runner
            ->expects(self::once())
            ->method('audit')
            ->with(
                self::callback(
                    static fn(AuditRequest $request): bool => Severity::LOW === $request->minimumSeverity
                        && !$request->noDev,
                ),
            )
            ->willReturn(new AuditReport('npm', []));

        $tester = $this->createTester($runner);

        self::assertSame(
            AuditCommand::STATUS_OK,
            $tester->execute([]),
            'Clean audit must return a successful status.',
        );
        self::assertSame(
            "No known frontend vulnerabilities found.\n",
            $tester->getDisplay(true),
            'Clean audit must report that no vulnerabilities were found.',
        );
    }

    #[DataProviderExternal(AuditCommandProvider::class, 'invalidAuditLevels')]
    public function testInvalidAuditLevelReturnsOperationalFailureWithoutRunningAudit(string $auditLevel): void
    {
        $runner = $this->createMock(AuditRunnerInterface::class);

        $runner
            ->expects(self::never())
            ->method('audit');

        $io = $this->createMock(IOInterface::class);

        $io
            ->expects(self::once())
            ->method('writeError')
            ->with('<error>The audit level must be low, moderate, high, or critical.</error>');

        $tester = $this->createTester($runner, io: $io);

        self::assertSame(
            AuditCommand::STATUS_FAILED,
            $tester->execute(['--audit-level' => $auditLevel]),
            'Invalid threshold must return an operational failure status.',
        );
        self::assertSame(
            '',
            $tester->getDisplay(true),
            'Invalid threshold must not produce standard output.',
        );
    }

    public function testInvalidFormatReturnsOperationalFailureWithoutRunningAudit(): void
    {
        $runner = $this->createMock(AuditRunnerInterface::class);

        $runner
            ->expects(self::never())
            ->method('audit');

        $io = $this->createMock(IOInterface::class);

        $io
            ->expects(self::once())
            ->method('writeError')
            ->with('<error>The audit output format must be table, plain, json, or summary.</error>');

        $tester = $this->createTester($runner, io: $io);

        self::assertSame(
            AuditCommand::STATUS_FAILED,
            $tester->execute(['--format' => 'xml']),
            'Invalid format must return an operational failure status.',
        );
        self::assertSame(
            '',
            $tester->getDisplay(true),
            'Invalid format must not produce standard output.',
        );
    }

    /**
     * @throws JsonException
     */
    public function testJsonKeepsDiagnosticsOnComposerErrorOutput(): void
    {
        $runner = $this->createMock(AuditRunnerInterface::class);

        $runner
            ->expects(self::once())
            ->method('audit')
            ->willReturn(new AuditReport('npm', [self::finding(Severity::HIGH)], "manager warning\nsecond line"));

        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::once())
            ->method('resolve')
            ->with('GHSA-aaaa-bbbb-cccc')
            ->willReturn(new CveResolution(['CVE-2026-12345'], CveStatus::RESOLVED));

        $io = $this->createMock(IOInterface::class);

        $io
            ->expects(self::once())
            ->method('writeError')
            ->with('manager warning second line');

        $tester = $this->createTester($runner, $resolver, $io);

        self::assertSame(
            AuditCommand::STATUS_VULNERABLE,
            $tester->execute(['--format' => AuditFormat::JSON->value]),
            'Findings must retain the vulnerable exit status.',
        );

        /** @var array $document */
        $document = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            1,
            $document['schema_version'],
            'JSON output must use schema version `1`.',
        );
        self::assertSame(
            'npm',
            $document['manager'],
            'JSON output must identify the package manager.',
        );
        self::assertTrue(
            $document['affected'],
            'JSON output must indicate affected dependencies.',
        );
        self::assertSame(
            ['CVE-2026-12345'],
            $document['advisories'][0]['cves'],
            'Resolved CVE identifiers must appear in JSON output.',
        );
        self::assertSame(
            CveStatus::RESOLVED->value,
            $document['advisories'][0]['cve_status'],
            'JSON output must preserve the resolved CVE status.',
        );
        self::assertStringNotContainsString(
            'manager warning',
            $tester->getDisplay(),
            'Diagnostics must not leak into machine-readable output.',
        );
    }

    /**
     * @throws JsonException
     */
    public function testNoCveSkipsResolverAndPreservesMachineReadableOutput(): void
    {
        $runner = $this->createMock(AuditRunnerInterface::class);

        $runner
            ->expects(self::once())
            ->method('audit')
            ->willReturn(new AuditReport('npm', [self::finding(Severity::LOW)]));

        $resolver = $this->createMock(CveResolverInterface::class);

        $resolver
            ->expects(self::never())
            ->method('resolve');

        $tester = $this->createTester($runner, $resolver);

        self::assertSame(
            AuditCommand::STATUS_VULNERABLE,
            $tester->execute(['--format' => AuditFormat::JSON->value, '--no-cve' => true]),
            'Findings must retain the vulnerable exit status.',
        );

        /** @var array $document */
        $document = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            [],
            $document['advisories'][0]['cves'],
            'CVE identifiers must remain empty when resolution is skipped.',
        );
        self::assertSame(
            CveStatus::NOT_REQUESTED->value,
            $document['advisories'][0]['cve_status'],
            'Skipped CVE resolution must be marked as not requested.',
        );
    }

    public function testRunnerFailureIsSanitizedAndReturnsOperationalFailure(): void
    {
        $runner = $this->createMock(AuditRunnerInterface::class);

        $runner
            ->expects(self::once())
            ->method('audit')
            ->willThrowException(new RuntimeException("Process\nfailed\0now"));

        $io = $this->createMock(IOInterface::class);

        $io
            ->expects(self::once())
            ->method('writeError')
            ->with('<error>Foxy audit failed: Process failed now</error>');

        $tester = $this->createTester($runner, io: $io);

        self::assertSame(
            AuditCommand::STATUS_FAILED,
            $tester->execute([]),
            'Runner failure must return an operational failure status.',
        );
        self::assertSame(
            '',
            $tester->getDisplay(true),
            'Runner failure must not produce standard output.',
        );
    }

    public function testRunnerFailurePreservesMessageWithInvalidUtf8(): void
    {
        $runner = $this->createMock(AuditRunnerInterface::class);

        $runner
            ->expects(self::once())
            ->method('audit')
            ->willThrowException(new RuntimeException(" Process \xFF\nfailed "));

        $io = $this->createMock(IOInterface::class);

        $io
            ->expects(self::once())
            ->method('writeError')
            ->with("<error>Foxy audit failed: Process \xFF failed</error>");

        $tester = $this->createTester($runner, io: $io);

        self::assertSame(
            AuditCommand::STATUS_FAILED,
            $tester->execute([]),
            'Runner failure must return an operational failure status.',
        );
        self::assertSame(
            '',
            $tester->getDisplay(true),
            'Runner failure must not produce standard output.',
        );
    }

    private function createTester(
        AuditRunnerInterface $runner,
        CveResolverInterface|null $resolver = null,
        IOInterface|null $io = null,
    ): CommandTester {
        $composer = $this->createMock(Composer::class);

        $composer
            ->method('getEventDispatcher')
            ->willReturn($this->createMock(EventDispatcher::class));

        $command = new AuditCommand($runner, cveResolver: $resolver);

        $command->setComposer($composer);
        $command->setIO($io ?? new NullIO());
        $command->setApplication(new Application());

        return new CommandTester($command);
    }

    private static function finding(Severity $severity): AuditFinding
    {
        return new AuditFinding(
            'example-package',
            $severity,
            'GHSA-aaaa-bbbb-cccc',
            '1234',
            'Example vulnerability',
            '<2.0.0',
            'https://github.com/advisories/GHSA-aaaa-bbbb-cccc',
        );
    }
}
