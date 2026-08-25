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
    private const string URL = 'https://api.github.com/advisories/GHSA-35jh-r3h4-6jhm';

    public function testResolverCollectsDeduplicatesAndSortsCves(): void
    {
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader(self::fixture('github-advisory-with-cves.json')),
        );

        $resolution = $resolver->resolve('ghsa-35JH-r3H4-6JHm');

        self::assertSame(CveStatus::RESOLVED, $resolution->status);
        self::assertSame(['CVE-2020-8203', 'CVE-2021-23337'], $resolution->cves);
    }

    public function testResolverDistinguishesAdvisoryWithoutAssignedCve(): void
    {
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader(self::fixture('github-advisory-without-cve.json')),
        );

        $resolution = $resolver->resolve(self::GHSA_ID);

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
            $this->downloader('{"ghsa_id":"GHSA-aaaa-bbbb-cccc","identifiers":[]}'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'GitHub returned a mismatched advisory document for GHSA-35jh-r3h4-6jhm.',
        );

        $resolver->resolve(self::GHSA_ID);
    }

    private function downloader(string $body): HttpDownloader&MockObject
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader
            ->expects(self::once())
            ->method('get')
            ->with(
                self::URL,
                [
                    'http' => [
                        'header' => [
                            'Accept: application/vnd.github+json',
                            'X-GitHub-Api-Version: 2022-11-28',
                        ],
                    ],
                ],
            )
            ->willReturn(new Response(['url' => self::URL], 200, [], $body));

        return $downloader;
    }
}
