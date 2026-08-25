<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Foxy\Audit\{CveStatus, GitHubAdvisoryCveResolver};
use Foxy\Exception\RuntimeException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

        $resolution = $resolver->resolve('ghsa-AAaa-bBBb-CccC');

        self::assertSame(CveStatus::RESOLVED, $resolution->status);
        self::assertSame(['CVE-2020-8203', 'CVE-2021-23337'], $resolution->cves);
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

        self::assertSame(CveStatus::NONE_ASSIGNED, $resolution->status);
        self::assertSame([], $resolution->cves);
    }

    public function testResolverRejectsInvalidGhsaBeforeRequest(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->expects(self::never())->method('get');
        $resolver = new GitHubAdvisoryCveResolver($downloader);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The advisory identifier "not-a-ghsa" is not a valid GHSA identifier.');

        $resolver->resolve('not-a-ghsa');
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
            'GitHub returned a mismatched advisory document for GHSA-35jh-r3h4-6jhm.',
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
