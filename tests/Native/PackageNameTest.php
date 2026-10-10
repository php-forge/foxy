<?php

declare(strict_types=1);

namespace Foxy\Tests\Native;

use Foxy\Native\PackageName;
use Foxy\Tests\Provider\PackageNameProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PackageName} validation of npm package names.
 *
 * {@see PackageNameProvider} for test case data providers.
 */
final class PackageNameTest extends TestCase
{
    #[DataProviderExternal(PackageNameProvider::class, 'validNames')]
    public function testIsValidAcceptsName(string $name): void
    {
        self::assertTrue(
            PackageName::isValid($name),
            'The name must match the grammar.',
        );
    }

    #[DataProviderExternal(PackageNameProvider::class, 'invalidNames')]
    public function testIsValidRejectsName(string $name): void
    {
        self::assertFalse(
            PackageName::isValid($name),
            'The name must not match the grammar.',
        );
    }
}
