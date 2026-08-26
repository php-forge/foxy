<?php

declare(strict_types=1);

namespace Foxy\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Foxy\Exception\RuntimeException;
use Foxy\Foxy;

final readonly class FoxyCommandProvider implements CommandProvider
{
    private Composer $composer;
    private IOInterface $io;
    private Foxy $plugin;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(array $arguments)
    {
        $composer = $arguments['composer'] ?? null;
        $io = $arguments['io'] ?? null;
        $plugin = $arguments['plugin'] ?? null;

        if (!$composer instanceof Composer || !$io instanceof IOInterface || !$plugin instanceof Foxy) {
            throw new RuntimeException('Composer provided invalid Foxy command capability arguments.');
        }

        $this->composer = $composer;
        $this->io = $io;
        $this->plugin = $plugin;
    }

    /**
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
