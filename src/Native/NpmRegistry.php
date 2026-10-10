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
use function unlink;

/**
 * Provides the npm registry access of the native manager: abbreviated metadata and integrity-verified tarballs.
 *
 * Requests answered with HTTP 429 are retried up to three times, waiting the `Retry-After` seconds (capped at 60)
 * or 1, 2, and 4 seconds. Tarballs are cached and verified against their Subresource Integrity value on every cache
 * hit and every download.
 */
final readonly class NpmRegistry implements NpmRegistryInterface
{
    public const string DEFAULT_URL = 'https://registry.npmjs.org';

    /**
     * Hash algorithms accepted in an integrity value, strongest first.
     */
    private const array ALGORITHMS = ['sha512', 'sha384', 'sha256', 'sha1'];
    private const string METADATA_ACCEPT = 'Accept: application/vnd.npm.install-v1+json';
    private const int RETRIES = 3;
    private const int RETRY_AFTER_LIMIT = 60;

    /**
     * @param HttpDownloader $httpDownloader Downloader that performs the requests.
     * @param Cache $cache Tarball cache.
     * @param IOInterface $io Output for the retry notices.
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
        $key = "tarball/{$package->name}/{$package->version}-" . sha1($resolved) . '.tgz';
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
     * Returns whether the file matches the strongest known algorithm of a Subresource Integrity value.
     */
    private function matchesIntegrity(string $file, string $integrity): bool
    {
        $tokens = preg_split('/\s+/', $integrity);

        foreach (self::ALGORITHMS as $algorithm) {
            foreach ($tokens as $token) {
                if (str_starts_with($token, "{$algorithm}-")) {
                    return hash_equals(
                        (string) base64_decode(substr($token, strlen($algorithm) + 1), true),
                        hash_file($algorithm, $file, true),
                    );
                }
            }
        }

        return false;
    }

    private function registryUrl(): string
    {
        return rtrim($this->url, '/');
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
}
