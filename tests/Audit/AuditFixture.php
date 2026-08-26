<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use RuntimeException;

use function dirname;
use function file_get_contents;
use function sprintf;

trait AuditFixture
{
    private static function fixture(string $filename): string
    {
        $path = dirname(__DIR__) . '/Fixtures/Audit/' . $filename;
        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new RuntimeException(sprintf('Unable to read the audit fixture "%s".', $path));
        }

        return $contents;
    }
}
