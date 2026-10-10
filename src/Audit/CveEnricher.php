<?php

declare(strict_types=1);

namespace Foxy\Audit;

use Throwable;

use function array_fill_keys;
use function array_unique;
use function array_values;
use function sprintf;

/**
 * Adds CVE identifiers to audit findings, resolving every unique GHSA advisory in a single batch.
 */
final readonly class CveEnricher
{
    public function __construct(private CveResolverInterface $resolver) {}

    /**
     * Returns the report with a CVE resolution on every finding, in the original order.
     *
     * A failed lookup never fails the audit: its advisory is marked `unavailable` and reported once to the handler.
     *
     * @param AuditReport $report Report whose findings are enriched.
     * @param callable(string): void $warningHandler Receives one warning per GHSA identifier whose lookup failed.
     */
    public function enrich(AuditReport $report, callable $warningHandler): AuditReport
    {
        $findings = $report->findings;
        $pending = [];

        foreach ($findings as $index => $finding) {
            if ([] !== $finding->cves) {
                $findings[$index] = $finding->withCveResolution($finding->cves, CveStatus::RESOLVED);

                continue;
            }

            $ghsaId = AuditNormalizer::normalizeGhsaId($finding->advisoryId);

            if (null === $ghsaId) {
                $findings[$index] = $finding->withCveResolution([], CveStatus::UNAVAILABLE);

                continue;
            }

            $pending[$index] = $ghsaId;
        }

        $resolutions = [] === $pending ? [] : $this->resolveAll(array_values(array_unique($pending)), $warningHandler);

        foreach ($pending as $index => $ghsaId) {
            $resolution = $resolutions[$ghsaId];
            $findings[$index] = $findings[$index]->withCveResolution($resolution->cves, $resolution->status);
        }

        return $report->withFindings($findings);
    }

    /**
     * Returns the resolution of every GHSA identifier, marking each failed lookup as `unavailable` with one warning.
     *
     * @param list<string> $ghsaIds Unique canonical GHSA identifiers, in first-seen order.
     * @param callable(string): void $warningHandler Receives the warnings in the order of `$ghsaIds`.
     *
     * @return array<string, CveResolution> Resolutions keyed by GHSA identifier.
     */
    private function resolveAll(array $ghsaIds, callable $warningHandler): array
    {
        try {
            $results = $this->resolver->resolveMany($ghsaIds);
        } catch (Throwable $exception) {
            $results = array_fill_keys($ghsaIds, $exception);
        }

        $resolutions = [];

        foreach ($ghsaIds as $ghsaId) {
            $result = $results[$ghsaId];

            if ($result instanceof Throwable) {
                $warningHandler(
                    sprintf('Unable to resolve CVE identifiers for %s: %s', $ghsaId, $result->getMessage()),
                );
                $result = new CveResolution([], CveStatus::UNAVAILABLE);
            }

            $resolutions[$ghsaId] = $result;
        }

        return $resolutions;
    }
}
