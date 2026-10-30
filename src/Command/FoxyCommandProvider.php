<?php

declare(strict_types=1);

namespace Foxy\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Foxy;

/**
 * Provides Foxy's Composer console commands.
 */
final readonly class FoxyCommandProvider implements CommandProvider
{
    private Composer $composer;
    private IOInterface $io;
    private Foxy $plugin;

    /**
     * Creates the provider from Composer's capability arguments.
     *
     * @param array<string, mixed> $arguments Composer, I/O, and plugin instances supplied by Composer.
     */
    public function __construct(array $arguments)
    {
        $composer = $arguments['composer'] ?? null;
        $io = $arguments['io'] ?? null;
        $plugin = $arguments['plugin'] ?? null;

        if (!$composer instanceof Composer || !$io instanceof IOInterface || !$plugin instanceof Foxy) {
            throw new RuntimeException(
                Message::COMMAND_CAPABILITY_ARGUMENTS_INVALID->getMessage(),
            );
        }

        $this->composer = $composer;
        $this->io = $io;
        $this->plugin = $plugin;
    }

    /**
     * Returns the Foxy commands configured with the active Composer context.
     *
     * @return list<BaseCommand>
     */
    public function getCommands(): array
    {
        $command = new AuditCommand($this->plugin);

        $command->setComposer($this->composer);
        $command->setIO($this->io);

        return [$command];
    }
}
