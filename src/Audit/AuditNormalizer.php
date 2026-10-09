<?php

declare(strict_types=1);

namespace Foxy\Audit;

use function array_unique;
use function preg_match;
use function sort;
use function strtolower;

/**
 * Normalizes GHSA and CVE advisory identifiers and the string lists attached to audit findings.
 *
 * @internal
 */
final class AuditNormalizer
{
    private const string CVE_PATTERN = '/^CVE-\d{4}-\d{4,}$/i';
    private const string GHSA_CORE = 'GHSA-([A-Z0-9]{4})-([A-Z0-9]{4})-([A-Z0-9]{4})';
    private const string GHSA_PATTERN = '{^' . self::GHSA_CORE . '$}i';
    private const string GHSA_REFERENCE_PATTERN = '{^(?:https://github\.com/advisories/)?' . self::GHSA_CORE . '/?$}i';

    /**
     * Returns whether the value is a CVE identifier, matched case-insensitively.
     *
     * @param string $value Candidate identifier, already trimmed by the caller.
     */
    public static function isCveId(string $value): bool
    {
        return 1 === preg_match(self::CVE_PATTERN, $value);
    }

    /**
     * Returns the canonical GHSA identifier (uppercase prefix, lowercase segments), or `null` if the value is not a bare
     * GHSA identifier.
     *
     * @param string $value Candidate identifier, already trimmed by the caller.
     */
    public static function normalizeGhsaId(string $value): string|null
    {
        return self::canonicalizeGhsa(self::GHSA_PATTERN, $value);
    }

    /**
     * Returns the canonical GHSA identifier of a bare GHSA identifier or a `https://github.com/advisories/` URL with an
     * optional trailing slash, or `null` if the value is neither.
     *
     * @param string $value Candidate identifier or advisory URL.
     */
    public static function normalizeGhsaReference(string $value): string|null
    {
        return self::canonicalizeGhsa(self::GHSA_REFERENCE_PATTERN, $value);
    }

    /**
     * Returns the values without duplicates, sorted in ascending order and reindexed as a list.
     *
     * @param list<string> $values Values to deduplicate and sort.
     *
     * @return list<string>
     */
    public static function uniqueSorted(array $values): array
    {
        $values = array_unique($values);
        sort($values);

        return $values;
    }

    private static function canonicalizeGhsa(string $pattern, string $value): string|null
    {
        if (1 !== preg_match($pattern, $value, $matches)) {
            return null;
        }

        return 'GHSA-' . strtolower("{$matches[1]}-{$matches[2]}-{$matches[3]}");
    }
}
