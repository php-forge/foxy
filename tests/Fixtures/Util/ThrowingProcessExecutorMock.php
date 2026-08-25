<?php

declare(strict_types=1);

namespace Foxy\Tests\Fixtures\Util;

use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;
use Foxy\Exception\RuntimeException;

final class ThrowingProcessExecutorMock extends ProcessExecutor
{
    private bool $returnedFirstOutput = false;

    public function __construct(IOInterface $io, private readonly string|null $firstOutput = null)
    {
        parent::__construct($io);
    }

    public function execute($command, &$output = null, string|null $cwd = null): int
    {
        if (!$this->returnedFirstOutput && $this->firstOutput !== null) {
            $this->returnedFirstOutput = true;
            $output = $this->firstOutput;

            return 0;
        }

        throw new RuntimeException('Process execution failed.');
    }
}
