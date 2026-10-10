<?php

declare(strict_types=1);

namespace Foxy\Tests\Native;

use Composer\Cache;
use Composer\Downloader\TransportException;
use Composer\IO\{BufferIO, NullIO};
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Native\{NpmRegistry, ResolvedPackage};
use Foxy\Tests\Provider\NpmRegistryProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function mkdir;
use function sha1;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Unit tests for {@see NpmRegistry} metadata requests, 429 back-off, and cached, integrity-verified tarball downloads.
 *
 * {@see NpmRegistryProvider} for test case data providers.
 */
final class NpmRegistryTest extends TestCase
{
    private const array ACCEPT = ['http' => ['header' => ['Accept: application/vnd.npm.install-v1+json']]];
    private const string METADATA_URL = 'https://registry.test/@scope%2Fpkg';
    private const string TARBALL_URL = 'https://registry.test/@scope/pkg/-/pkg-1.0.0.tgz';

    private string $cacheDir = '';

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
            'Retrying "' . self::METADATA_URL . "\" after a 429 response ({$seconds} s)\n",
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

    private function registry(HttpDownloader $downloader): NpmRegistry
    {
        return new NpmRegistry(
            $downloader,
            $this->cache(),
            $this->io,
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
}
