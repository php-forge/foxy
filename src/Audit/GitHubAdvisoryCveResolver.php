<?php

declare(strict_types=1);

namespace Foxy\Audit;

use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Foxy\Exception\{Message, RuntimeException};
use Seld\JsonLint\ParsingException;
use Throwable;

use function array_map;
use function is_array;
use function is_string;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Resolves the CVE identifiers of GitHub security advisories through the GitHub global security advisories API.
 *
 * The lookups run concurrently, so the downloader must be the async-enabled instance returned by
 * `Composer::getLoop()->getHttpDownloader()`; any other instance throws a `LogicException` on the first request.
 *
 * @see https://docs.github.com/en/rest/security-advisories/global-advisories
 */
final readonly class GitHubAdvisoryCveResolver implements CveResolverInterface
{
    private const string API_URL = 'https://api.github.com/advisories/%s';

    public function __construct(private HttpDownloader $httpDownloader) {}

    /**
     * Resolves the CVE identifiers of several advisories with one concurrent request per identifier.
     *
     * @param list<string> $ghsaIds GHSA identifiers to resolve, trimmed and compared case-insensitively.
     *
     * @throws RuntimeException if an identifier is not a GHSA identifier; nothing is requested in that case.
     * @return array<string, CveResolution|Throwable> Resolutions keyed by canonical GHSA identifier; a `Throwable` is
     * the transport, JSON, or document error of that lookup.
     */
    public function resolveMany(array $ghsaIds): array
    {
        $ghsaIds = array_map($this->normalizeGhsaId(...), $ghsaIds);
        $results = [];

        foreach ($ghsaIds as $ghsaId) {
            $this->httpDownloader->add(
                sprintf(self::API_URL, $ghsaId),
                [
                    'http' => [
                        'header' => [
                            'Accept: application/vnd.github+json',
                            'X-GitHub-Api-Version: 2022-11-28',
                        ],
                    ],
                ],
            )->then(
                function (Response $response) use ($ghsaId, &$results): void {
                    try {
                        $results[$ghsaId] = $this->parse($ghsaId, $response);
                    } catch (Throwable $exception) {
                        $results[$ghsaId] = $exception;
                    }
                },
                static function (Throwable $exception) use ($ghsaId, &$results): void {
                    $results[$ghsaId] = $exception;
                },
            );
        }

        $this->httpDownloader->wait();

        return $results;
    }

    private function normalizeGhsaId(string $ghsaId): string
    {
        $ghsaId = trim($ghsaId);

        return AuditNormalizer::normalizeGhsaId($ghsaId) ?? throw new RuntimeException(
            Message::AUDIT_GHSA_ID_INVALID->getMessage($ghsaId),
        );
    }

    /**
     * Returns the CVE identifiers of an advisory document, unique and sorted.
     *
     * @param string $ghsaId Canonical GHSA identifier the document must describe.
     * @param Response $response Successful response of the advisory request.
     *
     * @throws ParsingException if the body is not valid JSON.
     * @throws RuntimeException if the document is not an object, describes another advisory, or has malformed
     * identifiers.
     */
    private function parse(string $ghsaId, Response $response): CveResolution
    {
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
}
