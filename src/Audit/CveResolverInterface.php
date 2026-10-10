<?php

declare(strict_types=1);

namespace Foxy\Audit;

use Foxy\Exception\RuntimeException;
use Throwable;

/**
 * Resolves the CVE identifiers of GitHub security advisories.
 */
interface CveResolverInterface
{
    /**
     * Resolves the CVE identifiers of several advisories at once.
     *
     * @param list<string> $ghsaIds GHSA identifiers to resolve.
     *
     * @return array<string, CveResolution|Throwable> One result for every identifier, keyed by canonical GHSA
     * identifier; a `Throwable` marks a lookup that failed.
     *
     * @throws RuntimeException if an identifier is not a GHSA identifier; nothing is requested in that case.
     */
    public function resolveMany(array $ghsaIds): array;
}
