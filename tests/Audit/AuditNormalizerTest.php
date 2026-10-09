<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Foxy\Audit\AuditNormalizer;
use Foxy\Tests\Provider\AuditNormalizerProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AuditNormalizer} identifier canonicalization and string list normalization.
 *
 * {@see AuditNormalizerProvider} for test case data providers.
 */
final class AuditNormalizerTest extends TestCase
{
    #[DataProviderExternal(AuditNormalizerProvider::class, 'cveIds')]
    public function testIsCveIdMatchesCveIdentifier(string $value, bool $expected): void
    {
        self::assertSame(
            $expected,
            AuditNormalizer::isCveId($value),
            'The CVE match result should match the expected value',
        );
    }

    #[DataProviderExternal(AuditNormalizerProvider::class, 'ghsaIds')]
    public function testNormalizeGhsaIdAcceptsOnlyBareIdentifier(string $value, string|null $expected): void
    {
        self::assertSame(
            $expected,
            AuditNormalizer::normalizeGhsaId($value),
            'The canonical GHSA identifier should match the expected value',
        );
    }

    #[DataProviderExternal(AuditNormalizerProvider::class, 'ghsaReferences')]
    public function testNormalizeGhsaReferenceAcceptsGitHubAdvisoryUrl(string $value, string|null $expected): void
    {
        self::assertSame(
            $expected,
            AuditNormalizer::normalizeGhsaReference($value),
            'The canonical GHSA identifier should match the expected value',
        );
    }

    /**
     * @param list<string> $values
     * @param list<string> $expected
     */
    #[DataProviderExternal(AuditNormalizerProvider::class, 'stringLists')]
    public function testUniqueSortedReturnsSortedListWithoutDuplicates(array $values, array $expected): void
    {
        self::assertSame(
            $expected,
            AuditNormalizer::uniqueSorted($values),
            'The values should be deduplicated, sorted and reindexed',
        );
    }
}
