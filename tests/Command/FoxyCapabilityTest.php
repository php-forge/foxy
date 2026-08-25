<?php

declare(strict_types=1);

namespace Foxy\Tests\Command;

use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;
use Composer\Plugin\Capable;
use Foxy\Asset\AssetManagerInterface;
use Foxy\Audit\{
    AuditProcessResult,
    AuditRequest,
    AuditRunnerInterface,
    AuditableAssetManagerInterface,
    Severity
};
use Foxy\Command\FoxyCommandProvider;
use Foxy\Config\Config;
use Foxy\Exception\RuntimeException;
use Foxy\Foxy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FoxyCapabilityTest extends TestCase
{
    public function testAuditDelegatesToSelectedAuditableManager(): void
    {
        $manager = $this->createMockForIntersectionOfInterfaces(
            [AssetManagerInterface::class, AuditableAssetManagerInterface::class],
        );
        $manager->expects(self::once())->method('getName')->willReturn('npm');
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
        $foxy = new Foxy();
        self::setProperty($foxy, 'config', new Config([], ['enabled' => true]));
        self::setProperty($foxy, 'assetManager', $manager);

        $report = $foxy->audit(new AuditRequest(Severity::HIGH, true));

        self::assertSame('npm', $report->manager);
        self::assertSame([], $report->findings);
        self::assertSame('manager diagnostic', $report->diagnostics);
    }

    public function testAuditRejectsDisabledPlugin(): void
    {
        $foxy = new Foxy();
        self::setProperty($foxy, 'config', new Config([], ['enabled' => false]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Foxy is disabled; frontend dependencies cannot be audited.');

        $foxy->audit(new AuditRequest());
    }

    public function testAuditRejectsManagerWithoutAuditCapability(): void
    {
        $foxy = new Foxy();
        self::setProperty($foxy, 'config', new Config([], ['enabled' => true]));
        self::setProperty($foxy, 'assetManager', $this->createMock(AssetManagerInterface::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The selected asset manager does not support security audits.');

        $foxy->audit(new AuditRequest());
    }

    public function testAuditRejectsMissingAssetManager(): void
    {
        $foxy = new Foxy();
        self::setProperty($foxy, 'config', new Config([], ['enabled' => true]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The selected asset manager does not support security audits.');

        $foxy->audit(new AuditRequest());
    }

    public function testAuditRejectsUnactivatedPlugin(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Foxy is disabled; frontend dependencies cannot be audited.');

        (new Foxy())->audit(new AuditRequest());
    }

    public function testDeclaresCommandProviderCapability(): void
    {
        $foxy = new Foxy();

        self::assertInstanceOf(Capable::class, $foxy);
        self::assertInstanceOf(AuditRunnerInterface::class, $foxy);
        self::assertSame(
            [ComposerCommandProvider::class => FoxyCommandProvider::class],
            $foxy->getCapabilities(),
        );
    }

    private static function setProperty(Foxy $foxy, string $property, object $value): void
    {
        (new ReflectionClass($foxy))->getProperty($property)->setValue($foxy, $value);
    }
}
