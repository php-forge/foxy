<?php

declare(strict_types=1);

namespace Foxy\Tests\Fixtures\Util;

use Composer\Util\ProcessExecutor;

use function count;

abstract class AbstractProcessExecutorMock extends ProcessExecutor
{
    private array $executedCommands = [];

    private array $expectedValues = [];

    private int $position = 0;

    /**
     * @param int $returnedCode The returned code
     * @param null $output       The output
     */
    public function addExpectedValues(int $returnedCode = 0, $output = null): static
    {
        $this->expectedValues[] = [$returnedCode, $output];

        return $this;
    }

    public function doExecute($command, &$output = null, string|null $cwd = null): int
    {
        $expected = $this->expectedValues[$this->position] ?? [0, $output];

        [$returnedCode, $output] = $expected;
        $this->executedCommands[] = [$command, $returnedCode, $output];
        ++$this->position;

        return $returnedCode;
    }

    /**
     * Get the executed command.
     *
     * @param int $position The position of executed command
     */
    public function getExecutedCommand(int $position): int|string|null
    {
        return $this->getExecutedValue($position, 0);
    }

    /**
     * Get the executed command.
     *
     * @param int $position The position of executed command
     */
    public function getExecutedOutput(int $position): int|string|null
    {
        return $this->getExecutedValue($position, 2);
    }

    /**
     * Get the executed returned code.
     *
     * @param int $position The position of executed command
     */
    public function getExecutedReturnedCode(int $position): int|string|null
    {
        return $this->getExecutedValue($position, 1);
    }

    /**
     * Get the last executed command.
     */
    public function getLastCommand(): int|string|null
    {
        return $this->getExecutedCommand(count($this->executedCommands) - 1);
    }

    /**
     * Get the last executed output.
     */
    public function getLastOutput(): int|string|null
    {
        return $this->getExecutedOutput(count($this->executedCommands) - 1);
    }

    /**
     * Get the last executed returned code.
     */
    public function getLastReturnedCode(): int|string|null
    {
        return $this->getExecutedReturnedCode(count($this->executedCommands) - 1);
    }

    /**
     * Get the value of the executed command.
     *
     * @param int $position The position
     * @param int $index The index of value
     */
    private function getExecutedValue(int $position, int $index): int|string|null
    {
        return isset($this->executedCommands[$position])
            ? $this->executedCommands[$position][$index]
            : null;
    }
}
