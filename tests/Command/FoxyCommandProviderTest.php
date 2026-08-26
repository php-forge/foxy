<?php

declare(strict_types=1);

namespace Foxy\Tests\Command;

use Composer\Composer;
use Composer\IO\IOInterface;
use Foxy\Command\{AuditCommand, FoxyCommandProvider};
use Foxy\Exception\RuntimeException;
use Foxy\Foxy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class FoxyCommandProviderTest extends TestCase
{
    public static function invalidArguments(): array
    {
        return [
            'composer' => ['composer'],
            'io' => ['io'],
            'plugin' => ['plugin'],
        ];
    }

    public function testProvidesAuditCommandWithActiveComposerAndIo(): void
    {
        $composer = $this->createMock(Composer::class);
        $io = $this->createMock(IOInterface::class);
        $provider = new FoxyCommandProvider(
            [
                'composer' => $composer,
                'io' => $io,
                'plugin' => new Foxy(),
            ],
        );

        $commands = $provider->getCommands();

        self::assertCount(1, $commands);
        self::assertInstanceOf(AuditCommand::class, $commands[0]);
        self::assertSame($composer, $commands[0]->requireComposer());
        self::assertSame($io, $commands[0]->getIO());
    }

    #[DataProvider('invalidArguments')]
    public function testRejectsInvalidCapabilityArgument(string $argument): void
    {
        $arguments = [
            'composer' => $this->createMock(Composer::class),
            'io' => $this->createMock(IOInterface::class),
            'plugin' => new Foxy(),
        ];
        $arguments[$argument] = new stdClass();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Composer provided invalid Foxy command capability arguments.');

        new FoxyCommandProvider($arguments);
    }

    public function testRejectsMissingCapabilityArguments(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Composer provided invalid Foxy command capability arguments.');

        new FoxyCommandProvider([]);
    }
}
