<?php

declare(strict_types=1);

/*
 * This file is part of the Foxy package.
 *
 * (c) François Pluchino <francois.pluchino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Foxy\Tests\Fixtures\Util;

use Composer\Util\ProcessExecutor;

/**
 * Mock of ProcessExecutor.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
abstract class AbstractProcessExecutorMock extends ProcessExecutor
{
    /**
     * @var array
     */
    private $expectedValues = [];

    /**
     * @var array
     */
    private $executedCommands = [];

    /**
     * @var int
     */
    private $position = 0;

    public function doExecute($command, &$output = null, ?string $cwd = null): int
    {
        $expected = $this->expectedValues[$this->position] ?? [0, $output];

        [$returnedCode, $output] = $expected;
        $this->executedCommands[] = [$command, $returnedCode, $output];
        ++$this->position;

        return $returnedCode;
    }

    /**
     * @param int  $returnedCode The returned code
     * @param null $output       The output
     *
     * @return self
     */
    public function addExpectedValues($returnedCode = 0, $output = null)
    {
        $this->expectedValues[] = [$returnedCode, $output];

        return $this;
    }

    /**
     * Get the executed command.
     *
     * @param int $position The position of executed command
     *
     * @return string|null
     */
    public function getExecutedCommand($position)
    {
        return $this->getExecutedValue($position, 0);
    }

    /**
     * Get the executed returned code.
     *
     * @param int $position The position of executed command
     *
     * @return int|null
     */
    public function getExecutedReturnedCode($position)
    {
        return $this->getExecutedValue($position, 1);
    }

    /**
     * Get the executed command.
     *
     * @param int $position The position of executed command
     *
     * @return string|null
     */
    public function getExecutedOutput($position)
    {
        return $this->getExecutedValue($position, 2);
    }

    /**
     * Get the last executed command.
     *
     * @return string|null
     */
    public function getLastCommand()
    {
        return $this->getExecutedCommand(\count($this->executedCommands) - 1);
    }

    /**
     * Get the last executed returned code.
     *
     * @return int|null
     */
    public function getLastReturnedCode()
    {
        return $this->getExecutedReturnedCode(\count($this->executedCommands) - 1);
    }

    /**
     * Get the last executed output.
     *
     * @return string|null
     */
    public function getLastOutput()
    {
        return $this->getExecutedOutput(\count($this->executedCommands) - 1);
    }

    /**
     * Get the value of the executed command.
     *
     * @param int $position The position
     * @param int $index    The index of value
     *
     * @return int|string|null
     */
    private function getExecutedValue($position, int $index)
    {
        return isset($this->executedCommands[$position])
            ? $this->executedCommands[$position][$index]
            : null;
    }
}
