<?php

declare(strict_types=1);

namespace Foxy\Tests\Native;

use Composer\Util\Filesystem;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Native\TarballExtractor;
use Foxy\Tests\Provider\TarballExtractorProvider;
use Foxy\Tests\Support\TarArchive;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function is_dir;
use function is_executable;
use function mkdir;
use function scandir;
use function sprintf;
use function str_repeat;
use function substr;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

/**
 * Unit tests for {@see TarballExtractor} extraction of synthetic ustar, PAX and GNU archives.
 *
 * {@see TarballExtractorProvider} for test case data providers.
 */
final class TarballExtractorTest extends TestCase
{
    private const string LABEL = 'https://registry.npmjs.org/acme/-/acme-1.0.0.tgz';

    private string $directory = '';

    public function testExtractCombinesPaxSizeWithGnuLongName(): void
    {
        $name = 'package/' . str_repeat('c', 130) . '/combined.txt';
        $gnu = ['magic' => 'ustar ', 'version' => " \0"];

        $destination = $this->extract(
            TarArchive::create()
                ->addPax(['size' => '5'])
                ->addEntry('L', '././@LongLink', "{$name}\0", fields: $gnu)
                ->addEntry('0', 'package/placeholder', 'hello', fields: $gnu + ['size' => '00000000000 '])
                ->addFile('package/after.txt', 'after')
                ->gzip(),
        );

        self::assertSame(
            'hello',
            $this->contents($destination . '/' . str_repeat('c', 130) . '/combined.txt'),
            'PAX size and GNU name must both apply.',
        );
        self::assertSame(
            'after',
            $this->contents("{$destination}/after.txt"),
            'Following entry must stay aligned.',
        );
    }

    public function testExtractCreatesDestinationForEmptyArchive(): void
    {
        $destination = $this->extract(TarArchive::create()->gzip(), 'out/deep');

        self::assertSame(
            ['.', '..'],
            scandir($destination),
            'Destination must exist and stay empty.',
        );
    }

    public function testExtractCreatesEmptyDirectory(): void
    {
        $destination = $this->extract(TarArchive::create()->addDirectory('package/empty/')->gzip());

        self::assertDirectoryExists(
            "{$destination}/empty",
            'Empty directory must be created.',
        );
    }

    public function testExtractCreatesNestedDirectoriesWithoutDirectoryEntries(): void
    {
        $destination = $this->extract(
            TarArchive::create()
                ->addFile('package/dist/nested/deep/c.js', 'c();')
                ->gzip(),
        );

        self::assertSame(
            'c();',
            $this->contents("{$destination}/dist/nested/deep/c.js"),
            'File must be written.',
        );
    }

    #[DataProviderExternal(TarballExtractorProvider::class, 'fileModes')]
    public function testExtractHonoursExecuteBit(int $mode, bool $executable): void
    {
        $destination = $this->extract(TarArchive::create()->addFile('package/bin/tool', 'run', $mode)->gzip());

        if ($executable) {
            self::assertSame(
                0o755,
                fileperms("{$destination}/bin/tool") & 0o777,
                sprintf('Mode %o must yield permissions 0755.', $mode),
            );

            return;
        }

        self::assertFalse(
            is_executable("{$destination}/bin/tool"),
            sprintf('Mode %o must not yield an executable file.', $mode),
        );
    }

    public function testExtractHonoursPaxSize(): void
    {
        $destination = $this->extract(
            TarArchive::create()
                ->addPax(['size' => '5'])
                ->addEntry('0', 'package/sized.txt', 'hello', fields: ['size' => '00000000000 '])
                ->addFile('package/after.txt', 'after')
                ->gzip(),
        );

        self::assertSame(
            'hello',
            $this->contents("{$destination}/sized.txt"),
            'PAX size must win over the header.',
        );
        self::assertSame(
            'after',
            $this->contents("{$destination}/after.txt"),
            'Following entry must stay aligned.',
        );
    }

    public function testExtractIgnoresGlobalPaxHeader(): void
    {
        $destination = $this->extract(
            TarArchive::create()
                ->addPax(['path' => 'package/global.txt'], true)
                ->addFile('package/own.txt', 'own')
                ->gzip(),
        );

        self::assertSame(
            'own',
            $this->contents("{$destination}/own.txt"),
            'Entry must keep its own name.',
        );
        self::assertFileDoesNotExist(
            "{$destination}/global.txt",
            'Global path must not rename the entry.',
        );
    }

    public function testExtractIgnoresPrefixWithGnuMagic(): void
    {
        $destination = $this->extract(
            TarArchive::create()
                ->addEntry(
                    '0',
                    'package/gnu.txt',
                    'gnu',
                    fields: ['magic' => 'ustar ', 'version' => " \0", 'prefix' => 'ignored'],
                )
                ->gzip(),
        );

        self::assertSame(
            'gnu',
            $this->contents("{$destination}/gnu.txt"),
            'GNU prefix area must not be joined.',
        );
    }

    public function testExtractJoinsUstarPrefix(): void
    {
        $directory = str_repeat('d', 60);
        $file = str_repeat('f', 60) . '.js';

        $destination = $this->extract(
            TarArchive::create()
                ->addFile("p/{$directory}/{$file}", 'prefixed')
                ->gzip(),
        );

        self::assertSame(
            'prefixed',
            $this->contents("{$destination}/{$directory}/{$file}"),
            'Prefix and name must be joined with a slash.',
        );
    }

    public function testExtractReadsUncompressedTar(): void
    {
        $destination = $this->extract(TarArchive::create()->addFile('package/plain.txt', 'plain')->bytes());

        self::assertSame(
            'plain',
            $this->contents("{$destination}/plain.txt"),
            'Plain tar data must pass through.',
        );
    }

    #[DataProviderExternal(TarballExtractorProvider::class, 'skippedEntries')]
    public function testExtractSkipsEntry(TarArchive $archive, string $name): void
    {
        $destination = $this->extract($archive->addFile('package/after.txt', 'after')->gzip());

        self::assertFileDoesNotExist(
            "{$destination}/" . substr($name, 8),
            'Entry must not be created.',
        );
        self::assertSame(
            'after',
            $this->contents("{$destination}/after.txt"),
            'Following entry must be extracted.',
        );
    }

    #[DataProviderExternal(TarballExtractorProvider::class, 'emptyRelativeNames')]
    public function testExtractSkipsEntryWithoutRelativePath(string $name): void
    {
        $destination = $this->extract(
            TarArchive::create()
                ->addDirectory($name)
                ->addFile($name, 'top')
                ->addFile('package/a.txt', 'a')
                ->gzip(),
        );

        self::assertSame(
            ['.', '..', 'a.txt'],
            scandir($destination),
            'Only the nested file must be written.',
        );
    }

    #[DataProviderExternal(TarballExtractorProvider::class, 'separatedNames')]
    public function testExtractSplitsNameOnBothSeparators(string $name): void
    {
        $destination = $this->extract(TarArchive::create()->addFile($name, 'a();')->gzip());

        self::assertSame(
            'a();',
            $this->contents("{$destination}/dist/a.js"),
            'Top directory must be stripped.',
        );
        self::assertSame(
            ['.', '..', 'dist'],
            scandir($destination),
            'No backslash name may be written.',
        );
    }

    #[DataProviderExternal(TarballExtractorProvider::class, 'regularFileTypes')]
    public function testExtractTreatsTypeflagAsRegularFile(string $type): void
    {
        $destination = $this->extract(TarArchive::create()->addFile('package/file.txt', 'typed', type: $type)->gzip());

        self::assertSame(
            'typed',
            $this->contents("{$destination}/file.txt"),
            'Entry must be written as a file.',
        );
    }

    public function testExtractUsesGnuLongName(): void
    {
        $directory = str_repeat('l', 128);

        $destination = $this->extract(
            TarArchive::create()
                ->addLongName("package/{$directory}/long-name.txt", 'long')
                ->gzip(),
        );

        self::assertSame(
            'long',
            $this->contents("{$destination}/{$directory}/long-name.txt"),
            'Full 150-byte name must be used.',
        );
        self::assertFileDoesNotExist(
            "{$destination}/" . str_repeat('l', 92),
            'Truncated header name must not be used.',
        );
    }

    public function testExtractUsesPaxPath(): void
    {
        $directory = str_repeat('p', 148);

        $destination = $this->extract(
            TarArchive::create()
                ->addPax(['mtime' => '1700000000.5', 'path' => "package/{$directory}/pax-name.txt", 'uid' => '0'])
                ->addFile('package/placeholder', 'pax')
                ->addFile('package/next.txt', 'next')
                ->gzip(),
        );

        self::assertSame(
            'pax',
            $this->contents("{$destination}/{$directory}/pax-name.txt"),
            'Full 170-byte name must be used.',
        );
        self::assertFileDoesNotExist(
            "{$destination}/placeholder",
            'Header name must be replaced.',
        );
        self::assertSame(
            'next',
            $this->contents("{$destination}/next.txt"),
            'Override must apply to one entry only.',
        );
    }

    #[DataProviderExternal(TarballExtractorProvider::class, 'topDirectories')]
    public function testExtractWritesNpmLayout(string $top): void
    {
        $destination = $this->extract(
            TarArchive::create()
                ->addDirectory("{$top}/")
                ->addFile("{$top}/package.json", '{"name":"acme","version":"1.0.0"}')
                ->addDirectory("{$top}/dist/")
                ->addFile("{$top}/dist/a.js", "a();\n")
                ->addDirectory("{$top}/dist/nested/")
                ->addFile("{$top}/dist/nested/b.css", str_repeat('b', 512))
                ->addFile("{$top}/dist/nested/c.css", str_repeat('c', 513))
                ->gzip(),
        );

        self::assertSame(
            '{"name":"acme","version":"1.0.0"}',
            $this->contents("{$destination}/package.json"),
            'Manifest must land at the destination root.',
        );
        self::assertSame("a();\n", $this->contents("{$destination}/dist/a.js"), 'Short file must keep its bytes.');
        self::assertSame(
            str_repeat('b', 512),
            $this->contents("{$destination}/dist/nested/b.css"),
            'Block-sized file must keep its bytes.',
        );
        self::assertSame(
            str_repeat('c', 513),
            $this->contents("{$destination}/dist/nested/c.css"),
            'File spanning two blocks must keep its bytes.',
        );
        self::assertSame(
            ['.', '..', 'dist', 'package.json'],
            scandir($destination),
            'Top directory must be stripped.',
        );
    }

    #[DataProviderExternal(TarballExtractorProvider::class, 'escapingNames')]
    public function testThrowRuntimeExceptionForEscapingEntry(string $name): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            $this->message(Message::NATIVE_TARBALL_REASON_ENTRY_ESCAPES->getMessage($name)),
        );

        $this->extract(TarArchive::create()->addFile($name, 'escape')->gzip());
    }

    #[DataProviderExternal(TarballExtractorProvider::class, 'invalidArchives')]
    public function testThrowRuntimeExceptionForInvalidArchive(string $bytes): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            $this->message(Message::NATIVE_TARBALL_REASON_NOT_USTAR->getMessage()),
        );

        $this->extract($bytes);
    }

    #[DataProviderExternal(TarballExtractorProvider::class, 'truncatedArchives')]
    public function testThrowRuntimeExceptionForTruncatedArchive(string $bytes): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            $this->message(Message::NATIVE_TARBALL_REASON_TRUNCATED->getMessage()),
        );

        $this->extract($bytes);
    }

    public function testThrowRuntimeExceptionWhenEntryDirectoryIsAFile(): void
    {
        file_put_contents("{$this->directory}/dist", 'not a directory');

        try {
            $this->extract(TarArchive::create()->addFile('package/dist/a.js', 'a();')->gzip(), '');

            self::fail('An entry below a file must not be written.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                $this->message(Message::NATIVE_TARBALL_REASON_WRITE_FAILED->getMessage('package/dist/a.js')),
                $exception->getMessage(),
                'Reason must name the entry.',
            );
            self::assertInstanceOf(
                \RuntimeException::class,
                $exception->getPrevious(),
                'Filesystem failure must be chained.',
            );
        }
    }

    public function testThrowRuntimeExceptionWhenFileTargetIsADirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            $this->message(Message::NATIVE_TARBALL_REASON_WRITE_FAILED->getMessage('package/dist')),
        );

        $this->extract(TarArchive::create()->addDirectory('package/dist/')->addFile('package/dist', 'file')->gzip());
    }

    public function testThrowRuntimeExceptionWhenTarballIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            $this->message(Message::NATIVE_TARBALL_REASON_UNREADABLE->getMessage()),
        );

        (new TarballExtractor(new Filesystem()))->extract(
            "{$this->directory}/missing.tgz",
            "{$this->directory}/out",
            self::LABEL,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_tarball_extractor_test_', true);

        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->removeDirectory($this->directory);

        parent::tearDown();
    }

    private function contents(string $path): string
    {
        self::assertFileExists($path, 'Extracted file must exist.');

        return (string) file_get_contents($path);
    }

    /**
     * Writes the archive bytes to disk, extracts them and returns the destination directory.
     */
    private function extract(string $bytes, string $destination = 'out'): string
    {
        $tarball = "{$this->directory}/archive.tgz";
        $target = '' === $destination ? $this->directory : "{$this->directory}/{$destination}";

        file_put_contents($tarball, $bytes);

        (new TarballExtractor(new Filesystem()))->extract($tarball, $target, self::LABEL);

        self::assertTrue(
            is_dir($target),
            'Destination must be a directory.',
        );

        return $target;
    }

    private function message(string $reason): string
    {
        return Message::NATIVE_TARBALL_INVALID->getMessage(self::LABEL, $reason);
    }
}
