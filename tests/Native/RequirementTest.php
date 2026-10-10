<?php

declare(strict_types=1);

namespace Foxy\Tests\Native;

use Foxy\Native\Requirement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Requirement} construction and its optional flag default.
 */
final class RequirementTest extends TestCase
{
    public function testConstructorDefaultsToRequired(): void
    {
        $requirement = new Requirement('bootstrap', '^5.3', '');

        self::assertSame(
            'bootstrap',
            $requirement->name,
            'Name must be kept.',
        );
        self::assertSame(
            '^5.3',
            $requirement->spec,
            'Specification must be kept as written.',
        );
        self::assertSame(
            '',
            $requirement->source,
            'Root source must be an empty string.',
        );
        self::assertFalse(
            $requirement->optional,
            'Requirements must be mandatory unless flagged.',
        );
    }

    public function testConstructorKeepsOptionalFlag(): void
    {
        $requirement = new Requirement('fsevents', '^2.3.0', 'chokidar@3.6.0', true);

        self::assertSame(
            'chokidar@3.6.0',
            $requirement->source,
            'Transitive source must be kept.',
        );
        self::assertTrue(
            $requirement->optional,
            'Optional flag must be kept.',
        );
    }
}
