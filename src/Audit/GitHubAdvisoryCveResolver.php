<?php

declare(strict_types=1);

namespace Foxy\Audit;

use Composer\Util\HttpDownloader;
use Foxy\Exception\{Message, RuntimeException};

use function is_array;
use function is_string;
use function sprintf;
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
                Message::AUDIT_GITHUB_ADVISORY_INVALID->getMessage($ghsaId),
            );
        }

        $responseGhsaId = $data['ghsa_id'] ?? null;

        if (!is_string($responseGhsaId) || $this->normalizeGhsaId($responseGhsaId) !== $ghsaId) {
            throw new RuntimeException(
                Message::AUDIT_GITHUB_ADVISORY_MISMATCHED->getMessage($ghsaId),
            );
        }

        $cves = [];
        $identifiers = $data['identifiers'] ?? [];

        if (!is_array($identifiers) || !array_is_list($identifiers)) {
            throw new RuntimeException(
                Message::AUDIT_GITHUB_IDENTIFIERS_INVALID->getMessage($ghsaId),
            );
        }

        foreach ($identifiers as $identifier) {
            if (!is_array($identifier) || 'CVE' !== ($identifier['type'] ?? null)) {
                continue;
            }

            $value = $identifier['value'] ?? null;

            if (is_string($value) && AuditNormalizer::isCveId(trim($value))) {
                $cves[] = strtoupper(trim($value));
            }
        }

        $cveId = $data['cve_id'] ?? null;

        if (is_string($cveId) && AuditNormalizer::isCveId(trim($cveId))) {
            $cves[] = strtoupper(trim($cveId));
        }

        $cves = AuditNormalizer::uniqueSorted($cves);

        return new CveResolution(
            $cves,
            [] === $cves ? CveStatus::NONE_ASSIGNED : CveStatus::RESOLVED,
        );
    }

    private function normalizeGhsaId(string $ghsaId): string
    {
        $ghsaId = trim($ghsaId);

        return AuditNormalizer::normalizeGhsaId($ghsaId) ?? throw new RuntimeException(
            Message::AUDIT_GHSA_ID_INVALID->getMessage($ghsaId),
        );
    }
}
