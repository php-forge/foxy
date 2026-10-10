<?php

declare(strict_types=1);

namespace Foxy\Native;

use Closure;
use Composer\Cache;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Foxy\Exception\{Message, RuntimeException};
use Throwable;

use function base64_decode;
use function hash_equals;
use function hash_file;
use function is_file;
use function json_encode;
use function min;
use function preg_match;
use function preg_split;
use function rtrim;
use function sha1;
use function sleep;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Provides the npm registry access of the native manager: abbreviated metadata, integrity-verified tarballs, and bulk
 * security advisories.
 *
 * Requests answered with HTTP 429 are retried up to three times, waiting the `Retry-After` seconds (capped at 60)
 * or 1, 2, and 4 seconds. Tarballs are cached and verified against their Subresource Integrity value on every cache
 * hit and every download; a prefetch fills the cache with parallel downloads and no retry.
 */
final readonly class NpmRegistry implements NpmRegistryInterface
{
    public const string DEFAULT_URL = 'https://registry.npmjs.org';

    /**
     * Hash algorithms accepted in an integrity value, strongest first.
     */
    private const array ALGORITHMS = ['sha512', 'sha384', 'sha256', 'sha1'];
    private const string BULK_ADVISORIES_PATH = '/-/npm/v1/security/advisories/bulk';
    private const string METADATA_ACCEPT = 'Accept: application/vnd.npm.install-v1+json';
    private const string PREFETCH_FAILED = '<comment>Prefetching the tarball of "%s" (%s) failed: %s</comment>';
    private const int RETRIES = 3;
    private const int RETRY_AFTER_LIMIT = 60;

    /**
     * @param HttpDownloader $httpDownloader Downloader that performs the requests; {@see prefetchTarballs()} needs the
     * async-enabled instance returned by `Composer::getLoop()->getHttpDownloader()`.
     * @param Cache $cache Tarball cache.
     * @param IOInterface $io Output for the retry and failed prefetch notices.
     * @param string $url Registry base URL; a trailing slash is ignored.
     * @param (Closure(int): void)|null $delay Replacement for `sleep()` during the 429 back-off, or `null` to sleep.
     */
    public function __construct(
        private HttpDownloader $httpDownloader,
        private Cache $cache,
        private IOInterface $io,
        private string $url = self::DEFAULT_URL,
        private Closure|null $delay = null,
    ) {}

    /**
     * Returns the path of a verified `.tgz` written inside the directory, from the cache when possible.
     *
     * A cached tarball that fails its integrity check is removed and downloaded again.
     *
     * @param ResolvedPackage $package Registry package; its `resolved` and `integrity` values must be set.
     * @param string $directory Existing directory that receives the tarball.
     *
     * @throws RuntimeException if the request fails or the tarball does not match its integrity value.
     */
    public function fetchTarball(ResolvedPackage $package, string $directory): string
    {
        $resolved = $package->resolved ?? '';
        $integrity = $package->integrity ?? '';
        $key = $this->tarballKey($package);
        $file = "{$directory}/" . str_replace(['@', '/'], ['', '-'], $package->name) . "-{$package->version}.tgz";

        if ($this->cache->copyTo($key, $file)) {
            if ($this->matchesIntegrity($file, $integrity)) {
                return $file;
            }

            $this->cache->remove($key);
        }

        $this->send($resolved, fn(): Response => $this->httpDownloader->copy($resolved, $file));

        if (!$this->matchesIntegrity($file, $integrity)) {
            unlink($file);

            throw new RuntimeException(
                Message::NATIVE_INTEGRITY_MISMATCH->getMessage($resolved),
            );
        }

        $this->cache->copyFrom($key, $file);

        return $file;
    }

    /**
     * Returns the registry's bulk advisories document for the given versions, as the registry wrote it.
     *
     * Sends one `POST` to npm's bulk advisories endpoint with a JSON object body; an empty response body is returned
     * as an empty string.
     *
     * @param array<string, non-empty-list<string>> $versions Locked versions keyed by package name.
     *
     * @throws RuntimeException if the request fails, including a registry that does not implement the endpoint.
     */
    public function getAdvisories(array $versions): string
    {
        $url = $this->registryUrl() . self::BULK_ADVISORIES_PATH;

        $response = $this->send(
            $url,
            fn(): Response => $this->httpDownloader->get(
                $url,
                [
                    'http' => [
                        'method' => 'POST',
                        'header' => ['Content-Type: application/json', 'Accept: application/json'],
                        'content' => json_encode((object) $versions, JSON_THROW_ON_ERROR),
                    ],
                ],
            ),
        );

        return $response->getBody() ?? '';
    }

    /**
     * Returns the abbreviated metadata of a package.
     *
     * @param string $name Package name, scoped names included (`@scope/name`).
     *
     * @throws RuntimeException if the package is unknown, the request fails, or the metadata is malformed.
     */
    public function getMetadata(string $name): PackageMetadata
    {
        $url = $this->registryUrl() . '/' . str_replace('/', '%2F', $name);

        $response = $this->send(
            $url,
            fn(): Response => $this->httpDownloader->get($url, ['http' => ['header' => [self::METADATA_ACCEPT]]]),
            Message::NATIVE_PACKAGE_NOT_FOUND->getMessage($name, $this->registryUrl()),
        );

        try {
            $document = $response->decodeJson();
        } catch (Throwable) {
            throw new RuntimeException(
                Message::NATIVE_METADATA_INVALID->getMessage(
                    $name,
                    Message::NATIVE_METADATA_REASON_JSON_INVALID->getMessage(),
                ),
            );
        }

        return PackageMetadata::fromDocument($name, $document);
    }

    /**
     * Downloads the tarballs missing from the cache in parallel, verifying each before it is cached.
     *
     * Nothing is downloaded when the cache is disabled or read-only, since the downloads could not be kept. A package
     * without a tarball URL is skipped. A download that fails, does not match its integrity value, or cannot be
     * cached is discarded with a verbose notice; {@see fetchTarball()} downloads it again and reports the error.
     * Every temporary file is removed before the method returns.
     *
     * @param list<ResolvedPackage> $packages Registry packages whose tarballs are wanted.
     */
    public function prefetchTarballs(array $packages): void
    {
        if (!$this->cache->isEnabled() || $this->cache->isReadOnly()) {
            return;
        }

        $queued = false;

        foreach ($packages as $package) {
            $resolved = $package->resolved ?? '';
            $key = $this->tarballKey($package);

            if ('' === $resolved || false !== $this->cache->getAge($key)) {
                continue;
            }

            $file = sys_get_temp_dir() . '/' . uniqid('foxy-tarball-', true) . '.tgz';

            $this->httpDownloader->addCopy($resolved, $file)->then(
                function () use ($package, $key, $file, $resolved): void {
                    try {
                        if ($this->matchesIntegrity($file, $package->integrity ?? '')) {
                            $this->cache->copyFrom($key, $file);
                        } else {
                            $this->reportPrefetchFailure(
                                $package,
                                Message::NATIVE_INTEGRITY_MISMATCH->getMessage($resolved),
                            );
                        }
                    } catch (Throwable $exception) {
                        $this->reportPrefetchFailure($package, $exception->getMessage());
                    }

                    unlink($file);
                },
                function (Throwable $exception) use ($package, $file): void {
                    if (is_file($file)) {
                        unlink($file);
                    }

                    $this->reportPrefetchFailure($package, $exception->getMessage());
                },
            );

            $queued = true;
        }

        if ($queued) {
            $this->httpDownloader->wait();
        }
    }

    /**
     * Returns whether the file matches any digest of the strongest known algorithm of a Subresource Integrity value.
     *
     * @see https://www.w3.org/TR/SRI/#does-response-match-metadatalist
     */
    private function matchesIntegrity(string $file, string $integrity): bool
    {
        $tokens = preg_split('/\s+/', $integrity);

        foreach (self::ALGORITHMS as $algorithm) {
            $digests = [];

            foreach ($tokens as $token) {
                if (str_starts_with($token, "{$algorithm}-")) {
                    $digests[] = (string) base64_decode(substr($token, strlen($algorithm) + 1), true);
                }
            }

            if ([] === $digests) {
                continue;
            }

            $hash = hash_file($algorithm, $file, true);

            foreach ($digests as $digest) {
                if (hash_equals($digest, $hash)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function registryUrl(): string
    {
        return rtrim($this->url, '/');
    }

    /**
     * Writes the verbose notice of a failed prefetch, naming the package and the cause.
     */
    private function reportPrefetchFailure(ResolvedPackage $package, string $reason): void
    {
        $this->io->writeError(
            sprintf(self::PREFETCH_FAILED, $package->name, $package->version, $reason),
            true,
            IOInterface::VERBOSE,
        );
    }

    /**
     * Returns the seconds to wait before a retry: the `Retry-After` integer capped at 60, else 1, 2, or 4.
     *
     * @param int $retry Zero-based retry number.
     */
    private function retryDelay(TransportException $exception, int $retry): int
    {
        $retryAfter = Response::findHeaderValue($exception->getHeaders() ?? [], 'Retry-After');

        if (null !== $retryAfter && 1 === preg_match('/^\d+$/', $retryAfter)) {
            return min((int) $retryAfter, self::RETRY_AFTER_LIMIT);
        }

        return 2 ** $retry;
    }

    /**
     * Runs a request, retrying HTTP 429 responses, and converts transport failures into Foxy exceptions.
     *
     * @param string $url Requested URL, used in messages.
     * @param Closure(): Response $request Request to run.
     * @param string|null $notFound Message thrown for an HTTP 404 response, or `null` to treat it as a failure.
     *
     * @throws RuntimeException if the request fails, returns HTTP 404 with a `$notFound` message, or keeps returning
     * HTTP 429.
     */
    private function send(string $url, Closure $request, string|null $notFound = null): Response
    {
        $retry = 0;

        while (true) {
            try {
                return $request();
            } catch (TransportException $exception) {
                $status = $exception->getStatusCode();

                if (null !== $notFound && 404 === $status) {
                    throw new RuntimeException(
                        $notFound,
                    );
                }

                if (429 !== $status || self::RETRIES === $retry) {
                    throw new RuntimeException(
                        Message::NATIVE_REGISTRY_REQUEST_FAILED->getMessage($url, $exception->getMessage()),
                    );
                }

                $seconds = $this->retryDelay($exception, $retry);

                $this->io->writeError(
                    sprintf('<comment>Retrying "%s" after a 429 response (%d s)</comment>', $url, $seconds),
                );

                ($this->delay ?? sleep(...))($seconds);

                $retry++;
            }
        }
    }

    /**
     * Returns the cache key of a package's tarball: name, version, and the SHA-1 of the tarball URL.
     */
    private function tarballKey(ResolvedPackage $package): string
    {
        return "tarball/{$package->name}/{$package->version}-" . sha1($package->resolved ?? '') . '.tgz';
    }
}
