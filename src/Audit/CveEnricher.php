<?php

declare(strict_types=1);

namespace Foxy\Audit;

use Throwable;

use function sprintf;

final readonly class CveEnricher
{
    public function __construct(private CveResolverInterface $resolver) {}

    /**
     * @param callable(string): void $warningHandler
     */
    public function enrich(AuditReport $report, callable $warningHandler): AuditReport
    {
        /** @var array<string, CveResolution> $cache */
        $cache = [];
        $findings = [];

        foreach ($report->findings as $finding) {
            if ([] !== $finding->cves) {
                $findings[] = $finding->withCveResolution($finding->cves, CveStatus::RESOLVED);

                continue;
            }

            $ghsaId = AuditNormalizer::normalizeGhsaId($finding->advisoryId);

            if (null === $ghsaId) {
                $findings[] = $finding->withCveResolution([], CveStatus::UNAVAILABLE);

                continue;
            }

            if (!isset($cache[$ghsaId])) {
                try {
                    $cache[$ghsaId] = $this->resolver->resolve($ghsaId);
                } catch (Throwable $exception) {
                    $warningHandler(sprintf('Unable to resolve CVE identifiers for %s: %s', $ghsaId, $exception->getMessage()));
                    $cache[$ghsaId] = new CveResolution([], CveStatus::UNAVAILABLE);
                }
            }

            $resolution = $cache[$ghsaId];
            $findings[] = $finding->withCveResolution($resolution->cves, $resolution->status);
        }

        return $report->withFindings($findings);
    }
}
