<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use Foxy\Exception\Message;

use function str_repeat;

/**
 * Data provider for {@see \Foxy\Tests\Native\PackageMetadataTest} test cases.
 */
final class PackageMetadataProvider
{
    private const string TARBALL = 'https://registry.npmjs.org/bootstrap/-/bootstrap-1.0.0.tgz';

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function malformedDocuments(): iterable
    {
        yield 'dependencies is not an object' => [
            self::document(['dependencies' => '^1.0.0']),
            self::reason(Message::NATIVE_METADATA_REASON_STRING_MAP_REQUIRED, 'versions.1.0.0.dependencies'),
        ];
        yield 'dependencies value is not a string' => [
            self::document(['dependencies' => ['jquery' => '^3.7', 'popper' => 2]]),
            self::reason(Message::NATIVE_METADATA_REASON_STRING_MAP_REQUIRED, 'versions.1.0.0.dependencies'),
        ];
        yield 'dist is missing' => [
            ['versions' => ['1.0.0' => ['name' => 'bootstrap']]],
            self::reason(Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED, 'versions.1.0.0.dist'),
        ];
        yield 'dist is not an object' => [
            ['versions' => ['1.0.0' => ['dist' => self::TARBALL]]],
            self::reason(Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED, 'versions.1.0.0.dist'),
        ];
        yield 'dist-tags is not an object' => [
            ['dist-tags' => 'latest', 'versions' => []],
            self::reason(Message::NATIVE_METADATA_REASON_STRING_MAP_REQUIRED, 'dist-tags'),
        ];
        yield 'dist-tags value is not a string' => [
            ['dist-tags' => ['latest' => '1.0.0', 'next' => 2], 'versions' => []],
            self::reason(Message::NATIVE_METADATA_REASON_STRING_MAP_REQUIRED, 'dist-tags'),
        ];
        yield 'document is a string' => [
            '{"versions":{}}',
            self::reason(Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED, 'the document'),
        ];
        yield 'document is null' => [
            null,
            self::reason(Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED, 'the document'),
        ];
        yield 'hash is missing' => [
            ['versions' => ['1.0.0' => ['dist' => ['tarball' => self::TARBALL]]]],
            self::reason(Message::NATIVE_METADATA_REASON_DIST_HASH_REQUIRED, 'versions.1.0.0'),
        ];
        yield 'integrity is empty and shasum is missing' => [
            ['versions' => ['1.0.0' => ['dist' => ['tarball' => self::TARBALL, 'integrity' => '']]]],
            self::reason(Message::NATIVE_METADATA_REASON_DIST_HASH_REQUIRED, 'versions.1.0.0'),
        ];
        yield 'optionalDependencies is not an object' => [
            self::document(['optionalDependencies' => 'fsevents']),
            self::reason(Message::NATIVE_METADATA_REASON_STRING_MAP_REQUIRED, 'versions.1.0.0.optionalDependencies'),
        ];
        yield 'peerDependencies is not an object' => [
            self::document(['peerDependencies' => true]),
            self::reason(Message::NATIVE_METADATA_REASON_STRING_MAP_REQUIRED, 'versions.1.0.0.peerDependencies'),
        ];
        yield 'peerDependenciesMeta is not an object' => [
            self::document(['peerDependenciesMeta' => 'optional']),
            self::reason(Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED, 'versions.1.0.0.peerDependenciesMeta'),
        ];
        yield 'shasum has 39 characters' => [
            ['versions' => ['1.0.0' => ['dist' => ['tarball' => self::TARBALL, 'shasum' => str_repeat('a', 39)]]]],
            self::reason(Message::NATIVE_METADATA_REASON_DIST_HASH_REQUIRED, 'versions.1.0.0'),
        ];
        yield 'shasum has 41 characters' => [
            ['versions' => ['1.0.0' => ['dist' => ['tarball' => self::TARBALL, 'shasum' => str_repeat('a', 41)]]]],
            self::reason(Message::NATIVE_METADATA_REASON_DIST_HASH_REQUIRED, 'versions.1.0.0'),
        ];
        yield 'shasum is not a string' => [
            ['versions' => ['1.0.0' => ['dist' => ['tarball' => self::TARBALL, 'shasum' => 1234567890]]]],
            self::reason(Message::NATIVE_METADATA_REASON_DIST_HASH_REQUIRED, 'versions.1.0.0'),
        ];
        yield 'shasum is uppercase' => [
            ['versions' => ['1.0.0' => ['dist' => ['tarball' => self::TARBALL, 'shasum' => str_repeat('A', 40)]]]],
            self::reason(Message::NATIVE_METADATA_REASON_DIST_HASH_REQUIRED, 'versions.1.0.0'),
        ];
        yield 'tarball is empty' => [
            ['versions' => ['1.0.0' => ['dist' => ['tarball' => '', 'integrity' => 'sha512-AAAA']]]],
            self::reason(Message::NATIVE_METADATA_REASON_STRING_REQUIRED, 'versions.1.0.0.dist.tarball'),
        ];
        yield 'tarball is missing' => [
            ['versions' => ['1.0.0' => ['dist' => ['integrity' => 'sha512-AAAA']]]],
            self::reason(Message::NATIVE_METADATA_REASON_STRING_REQUIRED, 'versions.1.0.0.dist.tarball'),
        ];
        yield 'tarball is not a string' => [
            ['versions' => ['1.0.0' => ['dist' => ['tarball' => ['url' => self::TARBALL]]]]],
            self::reason(Message::NATIVE_METADATA_REASON_STRING_REQUIRED, 'versions.1.0.0.dist.tarball'),
        ];
        yield 'version entry is not an object' => [
            ['versions' => ['1.0.0' => self::TARBALL]],
            self::reason(Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED, 'versions.1.0.0'),
        ];
        yield 'versions is missing' => [
            ['dist-tags' => ['latest' => '1.0.0']],
            self::reason(Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED, 'versions'),
        ];
        yield 'versions is not an object' => [
            ['versions' => '1.0.0'],
            self::reason(Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED, 'versions'),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function document(array $entry): array
    {
        return [
            'versions' => [
                '1.0.0' => [
                    'dist' => ['tarball' => self::TARBALL, 'integrity' => 'sha512-AAAA'],
                    ...$entry,
                ],
            ],
        ];
    }

    private static function reason(Message $reason, string $path): string
    {
        return Message::NATIVE_METADATA_INVALID->getMessage('bootstrap', $reason->getMessage($path));
    }
}
