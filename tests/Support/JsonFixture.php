<?php

declare(strict_types=1);

namespace Foxy\Tests\Support;

use RuntimeException;

use function file_get_contents;
use function rtrim;
use function sprintf;

trait JsonFixture
{
    private static function fixture(string $filename): string
    {
        $path = __DIR__ . '/../Fixtures/Json/' . $filename;
        $content = file_get_contents($path);

        if (false === $content) {
            throw new RuntimeException(sprintf('Unable to read JSON fixture "%s".', $path));
        }

        return $content;
    }

    private static function fixtureWithoutFinalNewline(string $filename): string
    {
        return rtrim(self::fixture($filename), "\r\n");
    }
}
