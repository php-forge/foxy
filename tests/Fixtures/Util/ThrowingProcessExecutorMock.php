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
 * Mock of ProcessExecutor that always throws.
 *
 * @copyright Copyright (C) 2026 Terabytesoftw.
 * @license https://opensource.org/license/mit/ MIT License
 */
final class ThrowingProcessExecutorMock extends ProcessExecutor
{
    public function execute($command, &$output = null, ?string $cwd = null): int
    {
        throw new \RuntimeException('Process execution failed.');
    }
}
