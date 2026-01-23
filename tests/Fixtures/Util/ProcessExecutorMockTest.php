<?php

declare(strict_types=1);

namespace Foxy\Tests\Fixtures\Util;

use PHPUnit\Framework\TestCase;

final class ProcessExecutorMockTest extends TestCase
{
    public function testExecuteWithExpectedValues(): void
    {
        $executor = new ProcessExecutorMock();

        $executor->addExpectedValues(0, 'TEST');
        $executor->addExpectedValues(42, 'TEST 2');

        $executor->execute('run', $output);
        $executor->execute('run2', $output2);

        self::assertSame('run', $executor->getExecutedCommand(0));
        self::assertSame(0, $executor->getExecutedReturnedCode(0));
        self::assertSame('TEST', $executor->getExecutedOutput(0));

        self::assertSame('run2', $executor->getExecutedCommand(1));
        self::assertSame(42, $executor->getExecutedReturnedCode(1));
        self::assertSame('TEST 2', $executor->getExecutedOutput(1));

        self::assertNull($executor->getExecutedCommand(2));
        self::assertNull($executor->getExecutedReturnedCode(2));
        self::assertNull($executor->getExecutedOutput(2));

        self::assertSame('run2', $executor->getLastCommand());
        self::assertSame(42, $executor->getLastReturnedCode());
        self::assertSame('TEST 2', $executor->getLastOutput());

        self::assertSame('TEST', $output);
        self::assertSame('TEST 2', $output2);
    }

    public function testExecuteWithoutExpectedValues(): void
    {
        $executor = new ProcessExecutorMock();

        $executor->execute('run', $output);

        self::assertSame('run', $executor->getExecutedCommand(0));
        self::assertEquals(0, $executor->getExecutedReturnedCode(0));
        self::assertNull($executor->getExecutedOutput(0));

        self::assertNull($executor->getExecutedCommand(1));
        self::assertNull($executor->getExecutedReturnedCode(1));
        self::assertNull($executor->getExecutedOutput(1));

        self::assertSame('run', $executor->getLastCommand());
        self::assertEquals(0, $executor->getLastReturnedCode());
        self::assertNull($executor->getLastOutput());

        self::assertNull($output);
    }
}
