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
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class AuditCommandTest extends TestCase
{
    public static function auditFormats(): array
    {
        return [
            'table' => [AuditFormat::TABLE->value],
            'plain' => [AuditFormat::PLAIN->value],
            'json' => [AuditFormat::JSON->value],
            'summary' => [AuditFormat::SUMMARY->value],
        ];
    }

    public static function invalidAuditLevels(): array
    {
        return [
            'informational is not a failure threshold' => [Severity::INFO->value],
            'unknown severity' => ['severe'],
        ];
    }

    #[DataProvider('auditFormats')]
    public function testAcceptsEveryDocumentedFormat(string $format): void
    {
        $runner = $this->createMock(AuditRunnerInterface::class);
        $runner->expects(self::once())->method('audit')->willReturn(new AuditReport('npm', []));
        $tester = $this->createTester($runner);

        self::assertSame(AuditCommand::STATUS_OK, $tester->execute(['--format' => $format]));
        self::assertNotSame('', $tester->getDisplay(true));
    }

    #[DataProvider('thresholdStatuses')]
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
        );
        self::assertStringContainsString('1 advisory affecting 1 package', $tester->getDisplay(true));
    }

    public function testCommandConfiguration(): void
    {
        $command = new AuditCommand($this->createMock(AuditRunnerInterface::class));
        $definition = $command->getDefinition();

        self::assertSame('foxy:audit', $command->getName());
        self::assertSame('Checks frontend dependencies for known security vulnerabilities', $command->getDescription());
        self::assertSame(AuditFormat::TABLE->value, $definition->getOption('format')->getDefault());
        self::assertSame('f', $definition->getOption('format')->getShortcut());
        self::assertSame(Severity::LOW->value, $definition->getOption('audit-level')->getDefault());
        self::assertFalse($definition->getOption('no-dev')->getDefault());
        self::assertFalse($definition->getOption('no-cve')->getDefault());
        self::assertStringContainsString('Exit status 0', $command->getHelp());
        self::assertStringContainsString('1 means at least one advisory', $command->getHelp());
        self::assertStringContainsString('2 means the audit could not be completed reliably', $command->getHelp());
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

        self::assertSame(AuditCommand::STATUS_OK, $tester->execute([]));
        self::assertSame("No known frontend vulnerabilities found.\n", $tester->getDisplay(true));
    }

    #[DataProvider('invalidAuditLevels')]
    public function testInvalidAuditLevelReturnsOperationalFailureWithoutRunningAudit(string $auditLevel): void
    {
        $runner = $this->createMock(AuditRunnerInterface::class);
        $runner->expects(self::never())->method('audit');
        $io = $this->createMock(IOInterface::class);
        $io
            ->expects(self::once())
            ->method('writeError')
            ->with('<error>The audit level must be low, moderate, high, or critical.</error>');
        $tester = $this->createTester($runner, io: $io);

        self::assertSame(AuditCommand::STATUS_FAILED, $tester->execute(['--audit-level' => $auditLevel]));
        self::assertSame('', $tester->getDisplay(true));
    }

    public function testInvalidFormatReturnsOperationalFailureWithoutRunningAudit(): void
    {
        $runner = $this->createMock(AuditRunnerInterface::class);
        $runner->expects(self::never())->method('audit');
        $io = $this->createMock(IOInterface::class);
        $io
            ->expects(self::once())
            ->method('writeError')
            ->with('<error>The audit output format must be table, plain, json, or summary.</error>');
        $tester = $this->createTester($runner, io: $io);

        self::assertSame(AuditCommand::STATUS_FAILED, $tester->execute(['--format' => 'xml']));
        self::assertSame('', $tester->getDisplay(true));
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
        $io->expects(self::once())->method('writeError')->with('manager warning second line');
        $tester = $this->createTester($runner, $resolver, $io);

        self::assertSame(
            AuditCommand::STATUS_VULNERABLE,
            $tester->execute(['--format' => AuditFormat::JSON->value]),
        );

        /** @var array $document */
        $document = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $document['schema_version']);
        self::assertSame('npm', $document['manager']);
        self::assertTrue($document['affected']);
        self::assertSame(['CVE-2026-12345'], $document['advisories'][0]['cves']);
        self::assertSame(CveStatus::RESOLVED->value, $document['advisories'][0]['cve_status']);
        self::assertStringNotContainsString('manager warning', $tester->getDisplay());
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
        $resolver->expects(self::never())->method('resolve');
        $tester = $this->createTester($runner, $resolver);

        self::assertSame(
            AuditCommand::STATUS_VULNERABLE,
            $tester->execute(['--format' => AuditFormat::JSON->value, '--no-cve' => true]),
        );

        /** @var array $document */
        $document = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $document['advisories'][0]['cves']);
        self::assertSame(CveStatus::NOT_REQUESTED->value, $document['advisories'][0]['cve_status']);
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

        self::assertSame(AuditCommand::STATUS_FAILED, $tester->execute([]));
        self::assertSame('', $tester->getDisplay(true));
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

        self::assertSame(AuditCommand::STATUS_FAILED, $tester->execute([]));
        self::assertSame('', $tester->getDisplay(true));
    }

    public static function thresholdStatuses(): array
    {
        return [
            'below threshold' => [Severity::MODERATE, Severity::HIGH, AuditCommand::STATUS_OK],
            'at threshold' => [Severity::HIGH, Severity::HIGH, AuditCommand::STATUS_VULNERABLE],
            'above threshold' => [Severity::CRITICAL, Severity::HIGH, AuditCommand::STATUS_VULNERABLE],
        ];
    }

    private function createTester(
        AuditRunnerInterface $runner,
        CveResolverInterface|null $resolver = null,
        IOInterface|null $io = null,
    ): CommandTester {
        $composer = $this->createMock(Composer::class);
        $composer->method('getEventDispatcher')->willReturn($this->createMock(EventDispatcher::class));
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
