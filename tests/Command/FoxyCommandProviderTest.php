<?php

declare(strict_types=1);

namespace Foxy\Tests\Command;

use Composer\Composer;
use Composer\IO\IOInterface;
use Foxy\Command\{AuditCommand, FoxyCommandProvider};
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Foxy;
use Foxy\Tests\Provider\FoxyCommandProviderProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for {@see FoxyCommandProvider} capability argument validation and audit command registration.
 *
 * {@see FoxyCommandProviderProvider} for test case data providers.
 */
final class FoxyCommandProviderTest extends TestCase
{
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

        self::assertCount(
            1,
            $commands,
            'Provider must register exactly one command.',
        );
        self::assertInstanceOf(
            AuditCommand::class,
            $commands[0],
            'Registered command must be an audit command.',
        );
        self::assertSame(
            $composer,
            $commands[0]->requireComposer(),
            'Command must retain the Composer instance.',
        );
        self::assertSame(
            $io,
            $commands[0]->getIO(),
            'Command must retain the IO instance.',
        );
    }

    #[DataProviderExternal(FoxyCommandProviderProvider::class, 'invalidArguments')]
    public function testRejectsInvalidCapabilityArgument(string $argument): void
    {
        $arguments = [
            'composer' => $this->createMock(Composer::class),
            'io' => $this->createMock(IOInterface::class),
            'plugin' => new Foxy(),
        ];

        $arguments[$argument] = new stdClass();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::COMMAND_CAPABILITY_ARGUMENTS_INVALID->getMessage(),
        );

        new FoxyCommandProvider($arguments);
    }

    public function testRejectsMissingCapabilityArguments(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::COMMAND_CAPABILITY_ARGUMENTS_INVALID->getMessage(),
        );

        new FoxyCommandProvider([]);
    }
}
