<?php

declare(strict_types=1);

namespace Foxy\Tests\Fixtures\Util;

class ProcessExecutorMock extends AbstractProcessExecutorMock
{
    public function execute($command, &$output = null, string|null $cwd = null): int
    {
        return $this->doExecute($command, $output, $cwd);
    }
}
