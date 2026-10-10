<?php

declare(strict_types=1);

namespace Foxy\Tests\Command;

use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;
use Composer\Plugin\Capable;
use Foxy\Asset\{AssetManagerFinder, AssetManagerInterface};
use Foxy\Audit\{AuditProcessResult, AuditRequest, AuditRunnerInterface, Severity};
use Foxy\Audit\Parser\NpmAuditParser;
use Foxy\Command\FoxyCommandProvider;
use Foxy\Config\Config;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Foxy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FoxyCapabilityTest extends TestCase
{
    public function testAuditDelegatesToSelectedManager(): void
    {
        $manager = $this->createMock(AssetManagerInterface::class);

        $manager
            ->expects(self::once())
            ->method('getName')
            ->willReturn('npm');
        $manager
            ->expects(self::once())
            ->method('audit')
            ->with(true)
            ->willReturn(
                new AuditProcessResult(
                    0,
                    '{"auditReportVersion":2,"vulnerabilities":{},"metadata":{"vulnerabilities":{"info":0,"low":0,"moderate":0,"high":0,"critical":0,"total":0},"dependencies":{"prod":0,"dev":0,"optional":0,"peer":0,"peerOptional":0,"total":0}}}',
                    ' manager diagnostic ',
                ),
            );
        $manager
            ->expects(self::once())
            ->method('getAuditParser')
            ->willReturn(new NpmAuditParser());

        $foxy = new Foxy();

        self::setProperty($foxy, 'config', new Config([], ['enabled' => true]));
        self::setProperty($foxy, 'assetManager', $manager);

        $report = $foxy->audit(new AuditRequest(Severity::HIGH, true));

        self::assertSame(
            'npm',
            $report->manager,
            'Report must identify the selected package manager.',
        );
        self::assertSame(
            [],
            $report->findings,
            'Empty audit result must contain no findings.',
        );
        self::assertSame(
            'manager diagnostic',
            $report->diagnostics,
            'Manager diagnostics must be trimmed.',
        );
    }

    public function testDeclaresCommandProviderCapability(): void
    {
        $foxy = new Foxy();

        self::assertInstanceOf(
            Capable::class,
            $foxy,
            'Plugin must expose Composer capability support.',
        );
        self::assertInstanceOf(
            AuditRunnerInterface::class,
            $foxy,
            'Plugin must implement the audit runner contract.',
        );
        self::assertSame(
            [ComposerCommandProvider::class => FoxyCommandProvider::class],
            $foxy->getCapabilities(),
            'Command capability must map to its provider.',
        );
    }

    public function testThrowRuntimeExceptionWhenAuditFindsNoAssetManager(): void
    {
        $foxy = new Foxy();

        self::setProperty($foxy, 'config', new Config(['enabled' => true]));
        self::setProperty($foxy, 'assetManagerFinder', new AssetManagerFinder([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_MANAGER_NONE_FOUND->getMessage(),
        );

        $foxy->audit(new AuditRequest());
    }

    public function testThrowRuntimeExceptionWhenPluginIsDisabled(): void
    {
        $foxy = new Foxy();

        self::setProperty($foxy, 'config', new Config([], ['enabled' => false]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::FOXY_AUDIT_DISABLED->getMessage(),
        );

        $foxy->audit(new AuditRequest());
    }

    public function testThrowRuntimeExceptionWhenPluginIsNotActivated(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::FOXY_AUDIT_DISABLED->getMessage(),
        );

        (new Foxy())->audit(new AuditRequest());
    }

    private static function setProperty(Foxy $foxy, string $property, object $value): void
    {
        (new ReflectionClass($foxy))->getProperty($property)->setValue($foxy, $value);
    }
}
