<?php

declare(strict_types=1);

namespace Foxy\Tests\Fixtures\Util;

use Composer\Util\ProcessExecutor;
use Foxy\Exception\RuntimeException;

final class ThrowingProcessExecutorMock extends ProcessExecutor
{
    public function execute($command, &$output = null, string|null $cwd = null): int
    {
        throw new RuntimeException('Process execution failed.');
    }
}
