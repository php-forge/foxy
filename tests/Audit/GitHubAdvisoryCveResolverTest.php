<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Foxy\Audit\{CveStatus, GitHubAdvisoryCveResolver};
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Tests\Provider\GitHubAdvisoryCveResolverProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see GitHubAdvisoryCveResolver} GHSA validation and CVE resolution from GitHub advisories.
 *
 * {@see GitHubAdvisoryCveResolverProvider} for test case data providers.
 */
final class GitHubAdvisoryCveResolverTest extends TestCase
{
    use AuditFixture;

    private const string GHSA_ID = 'GHSA-35jh-r3h4-6jhm';
    private const string GHSA_WITH_CVES_ID = 'GHSA-aaaa-bbbb-cccc';
    private const string GHSA_WITHOUT_CVE_ID = 'GHSA-dddd-eeee-ffff';

    public function testResolverCollectsDeduplicatesAndSortsCves(): void
    {
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader(
                self::fixture('github-advisory-with-cves.json'),
                self::GHSA_WITH_CVES_ID,
            ),
        );

        $resolution = $resolver->resolve(' ghsa-AAaa-bBBb-CccC ');

        self::assertSame(
            CveStatus::RESOLVED,
            $resolution->status,
            'Resolution status must be marked as resolved.',
        );
        self::assertSame(
            ['CVE-2020-8203', 'CVE-2021-23337'],
            $resolution->cves,
            'CVE identifiers must be unique and sorted.',
        );
    }

    public function testResolverDistinguishesAdvisoryWithoutAssignedCve(): void
    {
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader(
                self::fixture('github-advisory-without-cve.json'),
                self::GHSA_WITHOUT_CVE_ID,
            ),
        );

        $resolution = $resolver->resolve(self::GHSA_WITHOUT_CVE_ID);

        self::assertSame(
            CveStatus::NONE_ASSIGNED,
            $resolution->status,
            'Unassigned advisories must use the `NONE_ASSIGNED` status.',
        );
        self::assertSame(
            [],
            $resolution->cves,
            'No CVE identifiers must be returned.',
        );
    }

    public function testResolverNormalizesAndFiltersCveIdentifiers(): void
    {
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader(
                <<<'JSON'
                    {
                        "ghsa_id": "GHSA-35JH-R3H4-6JHM",
                        "cve_id": " cve-2021-9999 ",
                        "identifiers": [
                            {"type": "GHSA", "value": "CVE-2019-0001"},
                            {"type": "CVE", "value": " CVE-2023-0002 "},
                            {"type": "CVE", "value": "cve-2022-0001"},
                            {"type": "CVE", "value": "prefixCVE-2020-0001"},
                            {"type": "CVE", "value": "CVE-2020-0001suffix"}
                        ]
                    }
                    JSON,
                self::GHSA_ID,
            ),
        );

        $resolution = $resolver->resolve(self::GHSA_ID);

        self::assertSame(
            CveStatus::RESOLVED,
            $resolution->status,
            'Valid identifiers must produce a resolved status.',
        );
        self::assertSame(
            ['CVE-2021-9999', 'CVE-2022-0001', 'CVE-2023-0002'],
            $resolution->cves,
            'CVE identifiers must be normalized, validated, and sorted.',
        );
    }

    #[DataProviderExternal(GitHubAdvisoryCveResolverProvider::class, 'ghsaIdsWithSurroundingText')]
    public function testResolverRejectsGhsaWithSurroundingTextBeforeRequest(string $ghsaId): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::never())
            ->method('get');

        $resolver = new GitHubAdvisoryCveResolver($downloader);

        $this->expectException(RuntimeException::class);

        $resolver->resolve($ghsaId);
    }

    public function testResolverRejectsInvalidGhsaBeforeRequest(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::never())
            ->method('get');

        $resolver = new GitHubAdvisoryCveResolver($downloader);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_GHSA_ID_INVALID->getMessage('not-a-ghsa'),
        );

        $resolver->resolve('not-a-ghsa');
    }

    #[DataProviderExternal(GitHubAdvisoryCveResolverProvider::class, 'invalidIdentifiers')]
    public function testResolverRejectsInvalidIdentifiers(string $identifiers): void
    {
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader(
                '{"ghsa_id":"GHSA-35jh-r3h4-6jhm","identifiers":' . $identifiers . '}',
                self::GHSA_ID,
            ),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_GITHUB_IDENTIFIERS_INVALID->getMessage('GHSA-35jh-r3h4-6jhm'),
        );

        $resolver->resolve(self::GHSA_ID);
    }

    public function testResolverRejectsMismatchedAdvisoryDocument(): void
    {
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader(
                '{"ghsa_id":"GHSA-aaaa-bbbb-cccc","identifiers":[]}',
                self::GHSA_ID,
            ),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_GITHUB_ADVISORY_MISMATCHED->getMessage('GHSA-35jh-r3h4-6jhm'),
        );

        $resolver->resolve(self::GHSA_ID);
    }

    public function testResolverRejectsNonObjectAdvisoryDocument(): void
    {
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader('null', self::GHSA_ID),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_GITHUB_ADVISORY_INVALID->getMessage('GHSA-35jh-r3h4-6jhm'),
        );

        $resolver->resolve(self::GHSA_ID);
    }

    private function downloader(string $body, string $ghsaId): HttpDownloader&MockObject
    {
        $url = 'https://api.github.com/advisories/' . $ghsaId;
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader
            ->expects(self::once())
            ->method('get')
            ->with(
                $url,
                [
                    'http' => [
                        'header' => [
                            'Accept: application/vnd.github+json',
                            'X-GitHub-Api-Version: 2022-11-28',
                        ],
                    ],
                ],
            )
            ->willReturn(new Response(['url' => $url], 200, [], $body));

        return $downloader;
    }
}
