<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use function base64_encode;
use function hash;

/**
 * Data provider for {@see \Foxy\Tests\Native\NpmRegistryTest} test cases.
 */
final class NpmRegistryProvider
{
    public const string TARBALL = "package/index.js\0module.exports = 'acme';\n";

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedIntegrities(): iterable
    {
        yield 'sha1 only' => [self::sri('sha1')];
        yield 'sha256 only' => [self::sri('sha256')];
        yield 'sha384 only' => [self::sri('sha384')];
        yield 'sha512 only' => [self::sri('sha512')];
        yield 'sha512 matching after a wrong sha512' => [self::sri('sha512', 'other') . ' ' . self::sri('sha512')];
        yield 'sha512 preferred over a wrong sha1' => [self::sri('sha1', 'other') . ' ' . self::sri('sha512')];
        yield 'tab-separated tokens' => [self::sri('sha1', 'other') . "\t" . self::sri('sha256')];
        yield 'unknown token before a known one' => ['md5-AAAA ' . self::sri('sha384')];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedIntegrities(): iterable
    {
        yield 'digest is not base64' => ['sha512-!!!!'];
        yield 'digest of other bytes' => [self::sri('sha512', 'other')];
        yield 'empty value' => [''];
        yield 'unknown algorithm' => ['md5-' . base64_encode(hash('md5', self::TARBALL, true))];
        yield 'two wrong sha512 digests' => [self::sri('sha512', 'other') . ' ' . self::sri('sha512', 'another')];
        yield 'wrong sha512 beside a right sha1' => [self::sri('sha1') . ' ' . self::sri('sha512', 'other')];
        yield 'wrong sha512 before a right sha1' => [self::sri('sha512', 'other') . ' ' . self::sri('sha1')];
    }

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function retryAfterHeaders(): iterable
    {
        yield 'above the cap' => [['Retry-After: 120'], 60];
        yield 'absent' => [[], 1];
        yield 'HTTP date' => [['Retry-After: Wed, 21 Oct 2026 07:28:00 GMT'], 1];
        yield 'integer' => [['HTTP/2 429', 'Retry-After: 7'], 7];
        yield 'leading text' => [['Retry-After: x7'], 1];
        yield 'trailing text' => [['Retry-After: 7x'], 1];
    }

    private static function sri(string $algorithm, string $bytes = self::TARBALL): string
    {
        return $algorithm . '-' . base64_encode(hash($algorithm, $bytes, true));
    }
}
