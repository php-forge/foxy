<?php

declare(strict_types=1);

namespace Foxy\Tests\Native;

use Foxy\Exception\{Message, RuntimeException};
use Foxy\Native\NpmRange;
use Foxy\Tests\Provider\NpmRangeProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;

/**
 * Unit tests for {@see NpmRange} parsing and matching with node-semver semantics, prerelease rule included.
 *
 * {@see NpmRangeProvider} for test case data providers.
 */
final class NpmRangeTest extends TestCase
{
    public function testSatisfiesIgnoresBuildMetadata(): void
    {
        self::assertTrue(
            NpmRange::parse('1.2.3', 'bootstrap')->satisfies('1.2.3+meta'),
            'Build metadata must not prevent an exact match.',
        );
        self::assertTrue(
            NpmRange::parse('1.2.3', 'bootstrap')->satisfies('1.2.3+build-1'),
            'A hyphen inside build metadata must not read as a prerelease.',
        );
        self::assertTrue(
            NpmRange::parse('~1.2.3-beta.2', 'bootstrap')->satisfies('1.2.3-beta.2+meta'),
            'A prerelease with build metadata must keep its tuple.',
        );
    }

    /**
     * @param list<string> $expected
     */
    #[DataProviderExternal(NpmRangeProvider::class, 'matchingVersions')]
    public function testSatisfiesMatchesNodeSemver(string $range, array $expected): void
    {
        $parsed = NpmRange::parse($range, 'bootstrap');

        self::assertSame(
            $expected,
            array_values(array_filter(NpmRangeProvider::CANDIDATES, $parsed->satisfies(...))),
            'Matched candidates must equal the node-semver result.',
        );
    }

    public function testSatisfiesRejectsUnparsableVersion(): void
    {
        self::assertFalse(
            NpmRange::parse('*', 'bootstrap')->satisfies('not-a-version'),
            'A version Composer cannot normalize must never match.',
        );
    }

    #[DataProviderExternal(NpmRangeProvider::class, 'invalidRanges')]
    public function testThrowRuntimeExceptionForInvalidRange(string $range): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_RANGE_INVALID->getMessage($range, 'bootstrap'),
        );

        NpmRange::parse($range, 'bootstrap');
    }

    public function testToStringReturnsTrimmedOriginalRange(): void
    {
        self::assertSame(
            '^1.2.3',
            (string) NpmRange::parse(' ^1.2.3 ', 'x'),
            'Surrounding whitespace must be trimmed and the range kept as written.',
        );
    }
}
