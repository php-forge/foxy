<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use Foxy\Tests\Support\TarArchive;

use function str_repeat;

/**
 * Data provider for {@see \Foxy\Tests\Native\TarballExtractorTest} test cases.
 */
final class TarballExtractorProvider
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyRelativeNames(): iterable
    {
        yield 'dot directory' => ['./'];
        yield 'top directory with slash' => ['package/'];
        yield 'top directory without slash' => ['package'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapingNames(): iterable
    {
        yield 'absolute path' => ['/abs/x'];
        yield 'absolute path with backslashes' => ['\\abs\\x'];
        yield 'leading parent segment' => ['../escape.txt'];
        yield 'nested parent segment' => ['package/../escape.txt'];
        yield 'nested parent segment with backslashes' => ['package\\..\\escape.txt'];
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function fileModes(): iterable
    {
        yield 'group execute only' => [0o610, true];
        yield 'other execute only' => [0o601, true];
        yield 'other read and write' => [0o646, false];
        yield 'owner execute only' => [0o700, true];
        yield 'read only' => [0o444, false];
        yield 'read and write' => [0o644, false];
        yield 'world executable' => [0o755, true];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidArchives(): iterable
    {
        $file = static fn(array $fields): string => TarArchive::create()
            ->addEntry('0', 'package/empty.txt', fields: $fields)
            ->gzip();

        yield 'checksum field is not octal' => [$file(['checksum' => "12z4567\0"])];
        yield 'checksum mismatch' => [$file(['checksum' => "0000001\0"])];
        yield 'mode field is not octal' => [$file(['mode' => "0z\0"])];
        yield 'not a tar stream' => [str_repeat('This is not a tar archive. ', 40)];
        yield 'PAX record length too short' => [
            TarArchive::create()->addEntry('x', 'PaxHeader/entry', '7 path=')->gzip(),
        ];
        yield 'PAX record without length' => [
            TarArchive::create()
                ->addEntry('x', 'PaxHeader/entry', "z11 path=b\n")
                ->addFile('package/a.txt', 'a')
                ->gzip(),
        ];
        yield 'size field is not octal' => [$file(['size' => "0z\0"])];
        yield 'wrong magic' => [$file(['magic' => "tar\0\0\0"])];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function regularFileTypes(): iterable
    {
        yield 'contiguous file' => ['7'];
        yield 'NUL typeflag' => ["\0"];
        yield 'regular file' => ['0'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function separatedNames(): iterable
    {
        yield 'backslash separators' => ['package\\dist\\a.js'];
        yield 'mixed separators' => ['package\\dist/a.js'];
        yield 'slash separators' => ['package/dist/a.js'];
    }

    /**
     * @return iterable<string, array{TarArchive, string}>
     */
    public static function skippedEntries(): iterable
    {
        yield 'GNU long link name' => [
            TarArchive::create()->addEntry('K', '././@LongLink', "package/target.txt\0"),
            'package/link',
        ];
        yield 'hard link' => [TarArchive::create()->addHardLink('package/link', 'package/target.txt'), 'package/link'];
        yield 'symbolic link' => [TarArchive::create()->addSymlink('package/link', 'target.txt'), 'package/link'];
        yield 'unknown typeflag' => [
            TarArchive::create()->addEntry('S', 'package/sparse.bin', 'sparse data'),
            'package/sparse.bin',
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function topDirectories(): iterable
    {
        yield 'npm top directory' => ['package'];
        yield 'other top directory' => ['pkg'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function truncatedArchives(): iterable
    {
        $archive = TarArchive::create()->addFile('package/package.json', '{"name":"acme","version":"1.0.0"}');

        yield 'end-of-archive block missing' => [$archive->truncated(1024)];
        yield 'inside end-of-archive block' => [$archive->truncated(1300)];
        yield 'inside file data' => [$archive->truncated(600)];
        yield 'inside header' => [$archive->truncated(300)];
    }
}
