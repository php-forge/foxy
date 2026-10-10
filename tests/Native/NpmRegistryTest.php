<?php

declare(strict_types=1);

namespace Foxy\Tests\Native;

use Closure;
use Composer\Cache;
use Composer\Downloader\TransportException;
use Composer\IO\{BufferIO, IOInterface, NullIO};
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Native\{NpmRegistry, ResolvedPackage};
use Foxy\Tests\Provider\NpmRegistryProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function base64_encode;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function mkdir;
use function preg_quote;
use function React\Promise\reject;
use function React\Promise\resolve;
use function sha1;
use function sys_get_temp_dir;
use function uniqid;

use const PHP_EOL;

/**
 * Unit tests for {@see NpmRegistry} metadata and bulk advisories requests, 429 back-off, cached, integrity-verified
 * tarball downloads, and parallel tarball prefetches.
 *
 * {@see NpmRegistryProvider} for test case data providers.
 */
final class NpmRegistryTest extends TestCase
{
    private const array ACCEPT = ['http' => ['header' => ['Accept: application/vnd.npm.install-v1+json']]];
    private const string ADVISORIES_DOCUMENT = '{"lodash":[{"id":1106913,"severity":"high"}]}';
    private const string ADVISORIES_URL = 'https://registry.test/-/npm/v1/security/advisories/bulk';
    private const string LEFT_PAD_TARBALL = "package/index.js\0module.exports = 'left-pad';\n";
    private const string LEFT_PAD_URL = 'https://registry.test/left-pad/-/left-pad-1.3.0.tgz';
    private const string METADATA_URL = 'https://registry.test/@scope%2Fpkg';
    private const string TARBALL_URL = 'https://registry.test/@scope/pkg/-/pkg-1.0.0.tgz';

    private string $cacheDir = '';

    /**
     * @var list<array{string, string}> URL and target file of each parallel copy, in request order.
     */
    private array $copies = [];

    /**
     * @var list<int>
     */
    private array $delays = [];

    private string $directory = '';
    private BufferIO $io;
    private string $root = '';

    #[DataProviderExternal(NpmRegistryProvider::class, 'acceptedIntegrities')]
    public function testFetchTarballAcceptsIntegrity(string $integrity): void
    {
        $file = $this->registry($this->tarballDownloader(NpmRegistryProvider::TARBALL))
            ->fetchTarball($this->package($integrity), $this->directory);

        self::assertSame(
            NpmRegistryProvider::TARBALL,
            file_get_contents($file),
            'The verified tarball must be kept.',
        );
    }

    public function testFetchTarballDownloadsAndCachesOnColdCache(): void
    {
        $file = $this->registry($this->tarballDownloader(NpmRegistryProvider::TARBALL))
            ->fetchTarball($this->package(), $this->directory);

        self::assertSame(
            "{$this->directory}/scope-pkg-1.0.0.tgz",
            $file,
            'File name must drop `@` and replace `/` with `-`.',
        );
        self::assertSame(
            NpmRegistryProvider::TARBALL,
            file_get_contents($file),
            'Downloaded bytes must be written to the file.',
        );
        self::assertSame(
            NpmRegistryProvider::TARBALL,
            file_get_contents($this->cacheEntry()),
            'Tarball must be cached under its name, version, and URL hash.',
        );
    }

    public function testFetchTarballReplacesCorruptedCacheEntry(): void
    {
        $this->seedCache('corrupted');

        $file = $this->registry($this->tarballDownloader(NpmRegistryProvider::TARBALL))
            ->fetchTarball($this->package(), $this->directory);

        self::assertSame(
            NpmRegistryProvider::TARBALL,
            file_get_contents($file),
            'Downloaded bytes must replace the corrupted copy.',
        );
        self::assertSame(
            NpmRegistryProvider::TARBALL,
            file_get_contents($this->cacheEntry()),
            'Cache entry must hold the verified bytes.',
        );
    }

    public function testFetchTarballRetriesTooManyRequests(): void
    {
        $calls = 0;
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::exactly(2))
            ->method('copy')
            ->with(self::TARBALL_URL, "{$this->directory}/scope-pkg-1.0.0.tgz")
            ->willReturnCallback(
                static function (string $url, string $to) use (&$calls): Response {
                    if (0 === $calls++) {
                        throw self::transportException(429);
                    }

                    file_put_contents($to, NpmRegistryProvider::TARBALL);

                    return new Response(['url' => $url], 200, [], null);
                },
            );

        $file = $this->registry($downloader)->fetchTarball($this->package(), $this->directory);

        self::assertSame(
            NpmRegistryProvider::TARBALL,
            file_get_contents($file),
            'Retried download must be written.',
        );
        self::assertSame(
            [1],
            $this->delays,
            'First retry must wait one second.',
        );
    }

    public function testFetchTarballUsesWarmCache(): void
    {
        $this->seedCache(NpmRegistryProvider::TARBALL);

        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::never())
            ->method('copy');

        $file = $this->registry($downloader)->fetchTarball($this->package(), $this->directory);

        self::assertSame(
            NpmRegistryProvider::TARBALL,
            file_get_contents($file),
            'Cached bytes must be copied to the file.',
        );
    }

    public function testGetAdvisoriesPostsEmptyObjectAndReturnsEmptyStringForMissingBody(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::once())
            ->method('get')
            ->with(self::ADVISORIES_URL, self::advisoriesOptions('{}'))
            ->willReturn(new Response(['url' => self::ADVISORIES_URL], 200, [], null));

        self::assertSame(
            '',
            $this->registry($downloader)->getAdvisories([]),
            'A missing body must become an empty string.',
        );
    }

    public function testGetAdvisoriesPostsVersionsAsJsonObject(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::once())
            ->method('get')
            ->with(self::ADVISORIES_URL, self::advisoriesOptions('{"lodash":["4.17.15"],"minimist":["1.2.0"]}'))
            ->willReturn(new Response(['url' => self::ADVISORIES_URL], 200, [], self::ADVISORIES_DOCUMENT));

        self::assertSame(
            self::ADVISORIES_DOCUMENT,
            $this->registry($downloader)->getAdvisories(['lodash' => ['4.17.15'], 'minimist' => ['1.2.0']]),
            'The document must be returned as the registry wrote it.',
        );
    }

    public function testGetAdvisoriesRetriesTooManyRequests(): void
    {
        $calls = 0;
        $downloader = $this->createMock(HttpDownloader::class);
        $exception = self::transportException(429, [], 'Too Many Requests');

        $downloader
            ->expects(self::exactly(2))
            ->method('get')
            ->with(self::ADVISORIES_URL, self::advisoriesOptions('{"lodash":["4.17.15"]}'))
            ->willReturnCallback(
                static function () use (&$calls, $exception): Response {
                    if (0 === $calls++) {
                        throw $exception;
                    }

                    return new Response(['url' => self::ADVISORIES_URL], 200, [], self::ADVISORIES_DOCUMENT);
                },
            );

        self::assertSame(
            self::ADVISORIES_DOCUMENT,
            $this->registry($downloader)->getAdvisories(['lodash' => ['4.17.15']]),
            'The document of the retried request must be returned.',
        );
        self::assertSame(
            [1],
            $this->delays,
            'Back-off must follow the default schedule.',
        );
        self::assertSame(
            'Retrying "' . self::ADVISORIES_URL . '" after a 429 response (1 s)' . PHP_EOL,
            $this->io->getOutput(),
            'The retry must be announced.',
        );
    }

    public function testGetMetadataRequestsEscapedScopedNameWithAcceptHeader(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::once())
            ->method('get')
            ->with(self::METADATA_URL, self::ACCEPT)
            ->willReturn($this->metadataResponse());

        $metadata = $this->registry($downloader)->getMetadata('@scope/pkg');

        self::assertSame(
            '1.0.0',
            $metadata->getDistTag('latest'),
            'Dist-tags must be decoded.',
        );
        self::assertSame(
            self::TARBALL_URL,
            $metadata->getVersion('1.0.0')?->tarball,
            'Versions must be decoded.',
        );
    }

    #[DataProviderExternal(NpmRegistryProvider::class, 'retryAfterHeaders')]
    public function testGetMetadataRetriesTooManyRequests(array $headers, int $seconds): void
    {
        $downloader = $this->metadataDownloader(self::transportException(429, $headers));

        $metadata = $this->registry($downloader)->getMetadata('@scope/pkg');

        self::assertSame(
            '1.0.0',
            $metadata->getDistTag('latest'),
            'Metadata of the retried request must be returned.',
        );
        self::assertSame(
            [$seconds],
            $this->delays,
            'Back-off must follow `Retry-After` or the default schedule.',
        );
        self::assertSame(
            'Retrying "' . self::METADATA_URL . "\" after a 429 response ({$seconds} s)" . PHP_EOL,
            $this->io->getOutput(),
            'Each retry must be announced.',
        );
    }

    public function testGetMetadataSleepsWithoutDelayReplacement(): void
    {
        $downloader = $this->metadataDownloader(self::transportException(429, ['Retry-After: 0']));

        $registry = new NpmRegistry($downloader, $this->cache(), $this->io, 'https://registry.test');

        self::assertSame(
            '1.0.0',
            $registry->getMetadata('@scope/pkg')->getDistTag('latest'),
            'Default back-off must retry the request.',
        );
    }

    public function testPrefetchTarballsCachesVerifiedDownloadForLaterFetch(): void
    {
        $registry = $this->registry($this->prefetchDownloader(1, self::serve(NpmRegistryProvider::TARBALL)));

        $registry->prefetchTarballs([$this->package()]);

        self::assertSame(
            NpmRegistryProvider::TARBALL,
            file_get_contents($this->cacheEntry()),
            'Verified download must be cached.',
        );
        self::assertFileDoesNotExist(
            $this->copies[0][1],
            'Temporary file must be removed.',
        );
        self::assertSame(
            NpmRegistryProvider::TARBALL,
            file_get_contents($registry->fetchTarball($this->package(), $this->directory)),
            'Later fetch must be served from the cache.',
        );
    }

    public function testPrefetchTarballsDiscardsDownloadWithMismatchingIntegrity(): void
    {
        $io = self::verboseIo();

        $this->registry($this->prefetchDownloader(1, self::serve('corrupted')), io: $io)
            ->prefetchTarballs([$this->package()]);

        self::assertFileDoesNotExist(
            $this->cacheEntry(),
            'Mismatching download must not be cached.',
        );
        self::assertFileDoesNotExist(
            $this->copies[0][1],
            'Temporary file must be removed.',
        );
        self::assertSame(
            'Prefetching the tarball of "@scope/pkg" (1.0.0) failed: '
            . Message::NATIVE_INTEGRITY_MISMATCH->getMessage(self::TARBALL_URL) . PHP_EOL,
            $io->getOutput(),
            'Verbose notice must name the package and the mismatch.',
        );
    }

    public function testPrefetchTarballsDiscardsPartialFileOfRejectedDownload(): void
    {
        $io = self::verboseIo();

        $this->registry($this->prefetchDownloader(1, self::fail500(true)), io: $io)
            ->prefetchTarballs([$this->package()]);

        self::assertFileDoesNotExist(
            $this->cacheEntry(),
            'Rejected download must not be cached.',
        );
        self::assertFileDoesNotExist(
            $this->copies[0][1],
            'Partial temporary file must be removed.',
        );
        self::assertSame(
            'Prefetching the tarball of "@scope/pkg" (1.0.0) failed: Internal Server Error' . PHP_EOL,
            $io->getOutput(),
            'Verbose notice must name the package and the cause.',
        );
    }

    public function testPrefetchTarballsDownloadsMissingPackagesWithOneWait(): void
    {
        $this->registry(
            $this->prefetchDownloader(
                2,
                static fn(string $url, string $to): PromiseInterface => self::serve(
                    self::TARBALL_URL === $url ? NpmRegistryProvider::TARBALL : self::LEFT_PAD_TARBALL,
                )($url, $to),
            ),
        )->prefetchTarballs([$this->package(), $this->leftPad()]);

        self::assertSame(
            [self::TARBALL_URL, self::LEFT_PAD_URL],
            [$this->copies[0][0], $this->copies[1][0]],
            'Each missing tarball must be requested once, in order.',
        );
        self::assertNotSame(
            $this->copies[0][1],
            $this->copies[1][1],
            'Temporary files must have unique names.',
        );
        self::assertMatchesRegularExpression(
            '{^' . preg_quote(sys_get_temp_dir()) . '/foxy-tarball-[0-9a-f]{13}\d\.\d{8}\.tgz$}',
            $this->copies[0][1],
            'Temporary file must be a unique `.tgz` in the system temporary directory.',
        );
        self::assertSame(
            NpmRegistryProvider::TARBALL,
            file_get_contents($this->cacheEntry()),
            'First tarball must be cached.',
        );
        self::assertSame(
            self::LEFT_PAD_TARBALL,
            file_get_contents($this->leftPadCacheEntry()),
            'Second tarball must be cached.',
        );
        self::assertFileDoesNotExist(
            $this->copies[1][1],
            'Every temporary file must be removed.',
        );
    }

    public function testPrefetchTarballsHidesFailureNoticeAtNormalVerbosity(): void
    {
        $this->registry($this->prefetchDownloader(1, self::fail500(false)))->prefetchTarballs([$this->package()]);

        self::assertSame(
            '',
            $this->io->getOutput(),
            'Notice must be verbose only.',
        );
    }

    public function testPrefetchTarballsIgnoresRejectedDownload(): void
    {
        $io = self::verboseIo();

        $this->registry($this->prefetchDownloader(1, self::fail500(false)), io: $io)
            ->prefetchTarballs([$this->package()]);

        self::assertFileDoesNotExist(
            $this->cacheEntry(),
            'Rejected download must not be cached.',
        );
        self::assertFileDoesNotExist(
            $this->copies[0][1],
            'No temporary file may be left.',
        );
        self::assertSame(
            'Prefetching the tarball of "@scope/pkg" (1.0.0) failed: Internal Server Error' . PHP_EOL,
            $io->getOutput(),
            'Verbose notice must name the package and the cause.',
        );
    }

    public function testPrefetchTarballsRequestsNothingForCachedPackages(): void
    {
        $this->seedCache(NpmRegistryProvider::TARBALL);

        $this->registry($this->idleDownloader())->prefetchTarballs([$this->package()]);

        self::assertSame(
            [],
            $this->copies,
            'Cached tarball must not be requested.',
        );
    }

    public function testPrefetchTarballsRequestsNothingForEmptyList(): void
    {
        $this->registry($this->idleDownloader())->prefetchTarballs([]);

        self::assertSame(
            [],
            $this->copies,
            'No tarball must be requested.',
        );
    }

    public function testPrefetchTarballsRequestsNothingWithDisabledCache(): void
    {
        $cache = new class (new NullIO(), $this->cacheDir, 'a-z0-9_./') extends Cache {
            public function isEnabled(): bool
            {
                return false;
            }
        };

        $this->registry($this->idleDownloader(), $cache)->prefetchTarballs([$this->package()]);

        self::assertFileDoesNotExist(
            $this->cacheEntry(),
            'Disabled cache must stay empty.',
        );
    }

    public function testPrefetchTarballsRequestsNothingWithoutTarballUrl(): void
    {
        $this->registry($this->idleDownloader())->prefetchTarballs(
            [new ResolvedPackage('no-url', '1.0.0'), new ResolvedPackage('empty-url', '1.0.0', '', 'sha512-AAAA')],
        );

        self::assertSame(
            [],
            $this->copies,
            'Package without a tarball URL must be skipped.',
        );
    }

    public function testPrefetchTarballsRequestsNothingWithReadOnlyCache(): void
    {
        $cache = new Cache(new NullIO(), $this->cacheDir, 'a-z0-9_./', null, true);

        $this->registry($this->idleDownloader(), $cache)->prefetchTarballs([$this->package()]);

        self::assertFileDoesNotExist(
            $this->cacheEntry(),
            'Read-only cache must stay empty.',
        );
    }

    public function testPrefetchTarballsSkipsCachedPackageAndRequestsMissingOne(): void
    {
        $this->seedCache(NpmRegistryProvider::TARBALL);

        $this->registry($this->prefetchDownloader(1, self::serve(self::LEFT_PAD_TARBALL)))
            ->prefetchTarballs([$this->package(), $this->leftPad()]);

        self::assertSame(
            self::LEFT_PAD_URL,
            $this->copies[0][0],
            'Only the missing tarball must be requested.',
        );
        self::assertSame(
            self::LEFT_PAD_TARBALL,
            file_get_contents($this->leftPadCacheEntry()),
            'Missing tarball must be cached.',
        );
    }

    public function testThrowRuntimeExceptionForInvalidMetadataJson(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->method('get')
            ->willReturn(new Response(['url' => self::METADATA_URL], 200, [], '{"name":'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_METADATA_INVALID->getMessage(
                '@scope/pkg',
                Message::NATIVE_METADATA_REASON_JSON_INVALID->getMessage(),
            ),
        );

        $this->registry($downloader)->getMetadata('@scope/pkg');
    }

    #[DataProviderExternal(NpmRegistryProvider::class, 'rejectedIntegrities')]
    public function testThrowRuntimeExceptionForRejectedIntegrity(string $integrity): void
    {
        $registry = $this->registry($this->tarballDownloader(NpmRegistryProvider::TARBALL));

        try {
            $registry->fetchTarball($this->package($integrity), $this->directory);

            self::fail('The tarball must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::NATIVE_INTEGRITY_MISMATCH->getMessage(self::TARBALL_URL),
                $exception->getMessage(),
                'Mismatch must name the tarball URL.',
            );
        }

        self::assertFileDoesNotExist(
            "{$this->directory}/scope-pkg-1.0.0.tgz",
            'Rejected download must be deleted.',
        );
        self::assertFileDoesNotExist(
            $this->cacheEntry(),
            'Rejected download must not be cached.',
        );
    }

    public function testThrowRuntimeExceptionWhenAdvisoriesEndpointIsMissing(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::once())
            ->method('get')
            ->willThrowException(self::transportException(404, [], 'Not Found'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_REGISTRY_REQUEST_FAILED->getMessage(self::ADVISORIES_URL, 'Not Found'),
        );

        $this->registry($downloader)->getAdvisories(['lodash' => ['4.17.15']]);
    }

    public function testThrowRuntimeExceptionWhenDownloadFailsAfterCorruptedCacheHit(): void
    {
        $this->seedCache('corrupted');

        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->method('copy')
            ->willThrowException(self::transportException(500, [], 'Internal Server Error'));

        try {
            $this->registry($downloader)->fetchTarball($this->package(), $this->directory);

            self::fail('The failed download must throw.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::NATIVE_REGISTRY_REQUEST_FAILED->getMessage(self::TARBALL_URL, 'Internal Server Error'),
                $exception->getMessage(),
                'Failure must name the tarball URL and the cause.',
            );
        }

        self::assertFileDoesNotExist(
            $this->cacheEntry(),
            'Corrupted cache entry must be removed.',
        );
    }

    public function testThrowRuntimeExceptionWhenMetadataIsNotAnObject(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->method('get')
            ->willReturn(new Response(['url' => self::METADATA_URL], 200, [], '"x"'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_METADATA_INVALID->getMessage(
                '@scope/pkg',
                Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED->getMessage('the document'),
            ),
        );

        $this->registry($downloader)->getMetadata('@scope/pkg');
    }

    public function testThrowRuntimeExceptionWhenMetadataRequestFails(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::once())
            ->method('get')
            ->willThrowException(self::transportException(500, [], 'Internal Server Error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_REGISTRY_REQUEST_FAILED->getMessage(self::METADATA_URL, 'Internal Server Error'),
        );

        $this->registry($downloader)->getMetadata('@scope/pkg');
    }

    public function testThrowRuntimeExceptionWhenPackageIsNotFound(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::once())
            ->method('get')
            ->willThrowException(self::transportException(404, [], 'Not Found'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('@scope/pkg', 'https://registry.test'),
        );

        $this->registry($downloader)->getMetadata('@scope/pkg');
    }

    public function testThrowRuntimeExceptionWhenTarballIsNotFound(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->method('copy')
            ->willThrowException(self::transportException(404, [], 'Not Found'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_REGISTRY_REQUEST_FAILED->getMessage(self::TARBALL_URL, 'Not Found'),
        );

        $this->registry($downloader)->fetchTarball($this->package(), $this->directory);
    }

    public function testThrowRuntimeExceptionWhenTooManyRequestsPersist(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::exactly(4))
            ->method('get')
            ->willThrowException(self::transportException(429, [], 'Too Many Requests'));

        try {
            $this->registry($downloader)->getMetadata('@scope/pkg');

            self::fail('The exhausted retries must throw.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::NATIVE_REGISTRY_REQUEST_FAILED->getMessage(self::METADATA_URL, 'Too Many Requests'),
                $exception->getMessage(),
                'Failure must name the metadata URL and the cause.',
            );
        }

        self::assertSame(
            [1, 2, 4],
            $this->delays,
            'Three retries must back off exponentially.',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/' . uniqid('foxy_npm_registry_test_', true);
        $this->cacheDir = $this->root . '/cache';
        $this->directory = $this->root . '/download';
        $this->io = new BufferIO();

        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->removeDirectory($this->root);
    }

    /**
     * Returns the stream options of a bulk advisories request with the JSON body.
     *
     * @return array{http: array{method: string, header: list<string>, content: string}}
     */
    private static function advisoriesOptions(string $content): array
    {
        return [
            'http' => [
                'method' => 'POST',
                'header' => ['Content-Type: application/json', 'Accept: application/json'],
                'content' => $content,
            ],
        ];
    }

    private function cache(): Cache
    {
        return new Cache(new NullIO(), $this->cacheDir, 'a-z0-9_./');
    }

    private function cacheEntry(): string
    {
        return "{$this->cacheDir}/tarball/-scope/pkg/1.0.0-" . sha1(self::TARBALL_URL) . '.tgz';
    }

    private function cacheKey(): string
    {
        return 'tarball/@scope/pkg/1.0.0-' . sha1(self::TARBALL_URL) . '.tgz';
    }

    /**
     * Returns a promise factory that rejects a parallel copy with an HTTP 500 error, leaving a partial file if asked.
     *
     * @return Closure(string, string): PromiseInterface<Response>
     */
    private static function fail500(bool $partial): Closure
    {
        return static function (string $url, string $to) use ($partial): PromiseInterface {
            if ($partial) {
                file_put_contents($to, 'partial');
            }

            return reject(self::transportException(500, [], 'Internal Server Error'));
        };
    }

    /**
     * Returns a downloader that must receive neither a parallel copy nor a `wait()` call.
     */
    private function idleDownloader(): HttpDownloader&MockObject
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::never())
            ->method('addCopy');
        $downloader
            ->expects(self::never())
            ->method('wait');

        return $downloader;
    }

    private function leftPad(): ResolvedPackage
    {
        return new ResolvedPackage(
            'left-pad',
            '1.3.0',
            self::LEFT_PAD_URL,
            'sha512-' . base64_encode(hash('sha512', self::LEFT_PAD_TARBALL, true)),
        );
    }

    private function leftPadCacheEntry(): string
    {
        return "{$this->cacheDir}/tarball/left-pad/1.3.0-" . sha1(self::LEFT_PAD_URL) . '.tgz';
    }

    /**
     * Returns a downloader whose first metadata request fails with the exception and whose second one succeeds.
     */
    private function metadataDownloader(TransportException $exception): HttpDownloader&MockObject
    {
        $calls = 0;
        $response = $this->metadataResponse();
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::exactly(2))
            ->method('get')
            ->with(self::METADATA_URL, self::ACCEPT)
            ->willReturnCallback(
                static function () use (&$calls, $exception, $response): Response {
                    if (0 === $calls++) {
                        throw $exception;
                    }

                    return $response;
                },
            );

        return $downloader;
    }

    private function metadataResponse(): Response
    {
        return new Response(
            ['url' => self::METADATA_URL],
            200,
            [],
            '{"name":"@scope/pkg","dist-tags":{"latest":"1.0.0"},"versions":{"1.0.0":{"name":"@scope/pkg",'
            . '"version":"1.0.0","dist":{"tarball":"' . self::TARBALL_URL . '","integrity":"sha512-AAAA"}}}}',
        );
    }

    private function package(string|null $integrity = null): ResolvedPackage
    {
        return new ResolvedPackage(
            '@scope/pkg',
            '1.0.0',
            self::TARBALL_URL,
            $integrity ?? 'sha512-' . base64_encode(hash('sha512', NpmRegistryProvider::TARBALL, true)),
        );
    }

    /**
     * Returns a downloader that records the parallel copies into {@see $copies}, answers them through the factory, and
     * expects one `wait()` and no sequential copy.
     *
     * @param Closure(string, string): PromiseInterface<Response> $answer Promise factory for each copy.
     */
    private function prefetchDownloader(int $copies, Closure $answer): HttpDownloader&MockObject
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::exactly($copies))
            ->method('addCopy')
            ->willReturnCallback(
                function (string $url, string $to) use ($answer): PromiseInterface {
                    $this->copies[] = [$url, $to];

                    return $answer($url, $to);
                },
            );
        $downloader
            ->expects(self::once())
            ->method('wait');
        $downloader
            ->expects(self::never())
            ->method('copy');

        return $downloader;
    }

    private function registry(
        HttpDownloader $downloader,
        Cache|null $cache = null,
        IOInterface|null $io = null,
    ): NpmRegistry {
        return new NpmRegistry(
            $downloader,
            $cache ?? $this->cache(),
            $io ?? $this->io,
            'https://registry.test/',
            function (int $seconds): void {
                $this->delays[] = $seconds;
            },
        );
    }

    /**
     * Stores the bytes as the cached tarball of the test package.
     */
    private function seedCache(string $bytes): void
    {
        $source = "{$this->root}/seed.tgz";

        file_put_contents($source, $bytes);

        $this->cache()->copyFrom($this->cacheKey(), $source);
    }

    /**
     * Returns a promise factory that writes the bytes to the copy target and fulfils the copy.
     *
     * @return Closure(string, string): PromiseInterface<Response>
     */
    private static function serve(string $bytes): Closure
    {
        return static function (string $url, string $to) use ($bytes): PromiseInterface {
            file_put_contents($to, $bytes);

            return resolve(new Response(['url' => $url], 200, [], null));
        };
    }

    /**
     * Returns a downloader that writes the bytes to the requested file once.
     */
    private function tarballDownloader(string $bytes): HttpDownloader&MockObject
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $downloader
            ->expects(self::once())
            ->method('copy')
            ->with(self::TARBALL_URL, "{$this->directory}/scope-pkg-1.0.0.tgz")
            ->willReturnCallback(
                static function (string $url, string $to) use ($bytes): Response {
                    file_put_contents($to, $bytes);

                    return new Response(['url' => $url], 200, [], null);
                },
            );

        return $downloader;
    }

    /**
     * @param list<string> $headers
     */
    private static function transportException(
        int $status,
        array $headers = [],
        string $message = '',
    ): TransportException {
        $exception = new TransportException($message, $status);

        $exception->setStatusCode($status);
        $exception->setHeaders($headers);

        return $exception;
    }

    private static function verboseIo(): BufferIO
    {
        return new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);
    }
}
