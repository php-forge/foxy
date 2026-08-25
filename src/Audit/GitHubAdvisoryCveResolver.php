<?php

declare(strict_types=1);

namespace Foxy\Audit;

use Composer\Util\HttpDownloader;
use Foxy\Exception\RuntimeException;

use function array_unique;
use function array_values;
use function is_array;
use function is_string;
use function preg_match;
use function sort;
use function sprintf;
use function strtolower;
use function strtoupper;
use function trim;

final readonly class GitHubAdvisoryCveResolver implements CveResolverInterface
{
    private const string API_URL = 'https://api.github.com/advisories/%s';

    public function __construct(private HttpDownloader $httpDownloader) {}

    public function resolve(string $ghsaId): CveResolution
    {
        $ghsaId = $this->normalizeGhsaId($ghsaId);

        $response = $this->httpDownloader->get(
            sprintf(self::API_URL, $ghsaId),
            [
                'http' => [
                    'header' => [
                        'Accept: application/vnd.github+json',
                        'X-GitHub-Api-Version: 2022-11-28',
                    ],
                ],
            ],
        );
        $data = $response->decodeJson();

        if (!is_array($data)) {
            throw new RuntimeException(
                sprintf('GitHub returned an invalid advisory document for %s.', $ghsaId),
            );
        }

        $responseGhsaId = $data['ghsa_id'] ?? null;

        if (!is_string($responseGhsaId) || $this->normalizeGhsaId($responseGhsaId) !== $ghsaId) {
            throw new RuntimeException(
                sprintf('GitHub returned a mismatched advisory document for %s.', $ghsaId),
            );
        }

        $cves = [];
        $identifiers = $data['identifiers'] ?? [];

        if (!is_array($identifiers) || !array_is_list($identifiers)) {
            throw new RuntimeException(
                sprintf('GitHub returned invalid identifiers for %s.', $ghsaId),
            );
        }

        foreach ($identifiers as $identifier) {
            if (!is_array($identifier) || 'CVE' !== ($identifier['type'] ?? null)) {
                continue;
            }

            $value = $identifier['value'] ?? null;

            if (is_string($value) && $this->isCve($value)) {
                $cves[] = strtoupper($value);
            }
        }

        $cveId = $data['cve_id'] ?? null;

        if (is_string($cveId) && $this->isCve($cveId)) {
            $cves[] = strtoupper($cveId);
        }

        $cves = array_values(array_unique($cves));
        sort($cves);

        return new CveResolution(
            $cves,
            [] === $cves ? CveStatus::NONE_ASSIGNED : CveStatus::RESOLVED,
        );
    }

    private function isCve(string $value): bool
    {
        return 1 === preg_match('/^CVE-\d{4}-\d{4,}$/i', trim($value));
    }

    private function normalizeGhsaId(string $ghsaId): string
    {
        $ghsaId = trim($ghsaId);

        if (1 !== preg_match('/^GHSA-([A-Z0-9]{4})-([A-Z0-9]{4})-([A-Z0-9]{4})$/i', $ghsaId, $matches)) {
            throw new RuntimeException(
                sprintf('The advisory identifier "%s" is not a valid GHSA identifier.', $ghsaId),
            );
        }

        return 'GHSA-' . strtolower("{$matches[1]}-{$matches[2]}-{$matches[3]}");
    }
}
