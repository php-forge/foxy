<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Composer\Downloader\TransportException;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Foxy\Audit\{CveResolution, CveStatus, GitHubAdvisoryCveResolver};
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Tests\Provider\GitHubAdvisoryCveResolverProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use Seld\JsonLint\ParsingException;
use Throwable;

use function array_keys;
use function count;
use function React\Promise\reject;
use function React\Promise\resolve;
use function strlen;
use function substr;

/**
 * Unit tests for {@see GitHubAdvisoryCveResolver} GHSA validation and concurrent CVE resolution from GitHub advisories.
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
            $this->downloader([self::GHSA_WITH_CVES_ID => self::fixture('github-advisory-with-cves.json')]),
        );

        $results = $resolver->resolveMany([' ghsa-AAaa-bBBb-CccC ']);

        self::assertSame(
            [self::GHSA_WITH_CVES_ID],
            array_keys($results),
            'Result must be keyed by the canonical GHSA identifier.',
        );

        $resolution = $results[self::GHSA_WITH_CVES_ID];

        self::assertInstanceOf(
            CveResolution::class,
            $resolution,
            'Valid document must produce a resolution.',
        );
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
            $this->downloader([self::GHSA_WITHOUT_CVE_ID => self::fixture('github-advisory-without-cve.json')]),
        );

        $resolution = $resolver->resolveMany([self::GHSA_WITHOUT_CVE_ID])[self::GHSA_WITHOUT_CVE_ID];

        self::assertInstanceOf(
            CveResolution::class,
            $resolution,
            'Valid document must produce a resolution.',
        );
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

    public function testResolverIsolatesTransportFailureInBatch(): void
    {
        $failure = new TransportException('Not Found', 404);
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader(
                [
                    self::GHSA_ID => $failure,
                    self::GHSA_WITHOUT_CVE_ID => self::fixture('github-advisory-without-cve.json'),
                ],
            ),
        );

        $results = $resolver->resolveMany([self::GHSA_ID, self::GHSA_WITHOUT_CVE_ID]);

        self::assertSame(
            $failure,
            $results[self::GHSA_ID],
            'Rejected lookup must keep its own transport exception.',
        );
        self::assertInstanceOf(
            CveResolution::class,
            $results[self::GHSA_WITHOUT_CVE_ID],
            'Sibling lookup must still resolve.',
        );
    }

    public function testResolverNormalizesAndFiltersCveIdentifiers(): void
    {
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader(
                [
                    self::GHSA_ID => <<<'JSON'
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
                ],
            ),
        );

        $resolution = $resolver->resolveMany([self::GHSA_ID])[self::GHSA_ID];

        self::assertInstanceOf(
            CveResolution::class,
            $resolution,
            'Valid document must produce a resolution.',
        );
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

    public function testResolverReportsParsingExceptionForInvalidJsonBody(): void
    {
        $resolver = new GitHubAdvisoryCveResolver($this->downloader([self::GHSA_ID => '{']));

        self::assertInstanceOf(
            ParsingException::class,
            $resolver->resolveMany([self::GHSA_ID])[self::GHSA_ID],
            'Invalid JSON must be reported as that lookup\'s parsing exception.',
        );
    }

    #[DataProviderExternal(GitHubAdvisoryCveResolverProvider::class, 'invalidIdentifiers')]
    public function testResolverReportsRuntimeExceptionForInvalidIdentifiers(string $identifiers): void
    {
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader(
                [self::GHSA_ID => '{"ghsa_id":"GHSA-35jh-r3h4-6jhm","identifiers":' . $identifiers . '}'],
            ),
        );

        $result = $resolver->resolveMany([self::GHSA_ID])[self::GHSA_ID];

        self::assertInstanceOf(
            RuntimeException::class,
            $result,
            'Malformed identifiers must be reported as a Foxy runtime exception.',
        );
        self::assertSame(
            Message::AUDIT_GITHUB_IDENTIFIERS_INVALID->getMessage(self::GHSA_ID),
            $result->getMessage(),
            'Exception message must name the advisory.',
        );
    }

    public function testResolverReportsRuntimeExceptionForMismatchedAdvisoryDocument(): void
    {
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader([self::GHSA_ID => '{"ghsa_id":"GHSA-aaaa-bbbb-cccc","identifiers":[]}']),
        );

        $result = $resolver->resolveMany([self::GHSA_ID])[self::GHSA_ID];

        self::assertInstanceOf(
            RuntimeException::class,
            $result,
            'Mismatched document must be reported as a Foxy runtime exception.',
        );
        self::assertSame(
            Message::AUDIT_GITHUB_ADVISORY_MISMATCHED->getMessage(self::GHSA_ID),
            $result->getMessage(),
            'Exception message must name the requested advisory.',
        );
    }

    public function testResolverReportsRuntimeExceptionForNonObjectAdvisoryDocument(): void
    {
        $resolver = new GitHubAdvisoryCveResolver($this->downloader([self::GHSA_ID => 'null']));

        $result = $resolver->resolveMany([self::GHSA_ID])[self::GHSA_ID];

        self::assertInstanceOf(
            RuntimeException::class,
            $result,
            'Non-object document must be reported as a Foxy runtime exception.',
        );
        self::assertSame(
            Message::AUDIT_GITHUB_ADVISORY_INVALID->getMessage(self::GHSA_ID),
            $result->getMessage(),
            'Exception message must name the advisory.',
        );
    }

    public function testResolverRequestsEveryAdvisoryBeforeWaitingOnce(): void
    {
        $resolver = new GitHubAdvisoryCveResolver(
            $this->downloader(
                [
                    self::GHSA_WITH_CVES_ID => self::fixture('github-advisory-with-cves.json'),
                    self::GHSA_WITHOUT_CVE_ID => self::fixture('github-advisory-without-cve.json'),
                ],
            ),
        );

        $results = $resolver->resolveMany([self::GHSA_WITH_CVES_ID, self::GHSA_WITHOUT_CVE_ID]);

        self::assertSame(
            [self::GHSA_WITH_CVES_ID, self::GHSA_WITHOUT_CVE_ID],
            array_keys($results),
            'Every requested advisory must have a result.',
        );

        $first = $results[self::GHSA_WITH_CVES_ID];
        $second = $results[self::GHSA_WITHOUT_CVE_ID];

        self::assertInstanceOf(
            CveResolution::class,
            $first,
            'First advisory must resolve.',
        );
        self::assertInstanceOf(
            CveResolution::class,
            $second,
            'Second advisory must resolve.',
        );
        self::assertSame(
            ['CVE-2020-8203', 'CVE-2021-23337'],
            $first->cves,
            'First advisory must carry its own CVE identifiers.',
        );
        self::assertSame(
            CveStatus::NONE_ASSIGNED,
            $second->status,
            'Second advisory must carry its own status.',
        );
    }

    #[DataProviderExternal(GitHubAdvisoryCveResolverProvider::class, 'ghsaIdsWithSurroundingText')]
    public function testThrowRuntimeExceptionForGhsaWithSurroundingTextBeforeRequest(string $ghsaId): void
    {
        $resolver = new GitHubAdvisoryCveResolver($this->silentDownloader());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_GHSA_ID_INVALID->getMessage($ghsaId),
        );

        $resolver->resolveMany([$ghsaId]);
    }

    public function testThrowRuntimeExceptionForInvalidGhsaBeforeRequest(): void
    {
        $resolver = new GitHubAdvisoryCveResolver($this->silentDownloader());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_GHSA_ID_INVALID->getMessage('not-a-ghsa'),
        );

        $resolver->resolveMany([self::GHSA_ID, 'not-a-ghsa']);
    }

    /**
     * Returns a downloader that answers each advisory URL once, then expects a single `wait()`.
     *
     * @param array<string, string|Throwable> $answers Response bodies or transport failures keyed by GHSA identifier.
     */
    private function downloader(array $answers): HttpDownloader&MockObject
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::exactly(count($answers)))
            ->method('add')
            ->willReturnCallback(
                static function (string $url, array $options) use (&$answers): PromiseInterface {
                    self::assertSame(
                        [
                            'http' => [
                                'header' => [
                                    'Accept: application/vnd.github+json',
                                    'X-GitHub-Api-Version: 2022-11-28',
                                ],
                            ],
                        ],
                        $options,
                        'Request must ask for the versioned GitHub JSON media type.',
                    );

                    $ghsaId = substr($url, strlen('https://api.github.com/advisories/'));

                    self::assertArrayHasKey(
                        $ghsaId, $answers,
                        'Request must target an expected advisory URL once.',
                    );

                    $answer = $answers[$ghsaId];
                    unset($answers[$ghsaId]);

                    return $answer instanceof Throwable
                        ? reject($answer)
                        : resolve(new Response(['url' => $url], 200, [], $answer));
                },
            );
        $downloader
            ->expects(self::once())
            ->method('wait');

        return $downloader;
    }

    private function silentDownloader(): HttpDownloader&MockObject
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::never())
            ->method('add');
        $downloader
            ->expects(self::never())
            ->method('wait');

        return $downloader;
    }
}
