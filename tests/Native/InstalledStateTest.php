<?php

declare(strict_types=1);

namespace Foxy\Tests\Native;

use Composer\IO\BufferIO;
use Composer\Util\Filesystem;
use Foxy\Native\InstalledState;
use Foxy\Tests\Provider\InstalledStateProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

use const PHP_EOL;

/**
 * Unit tests for {@see InstalledState} reading, validation, and sorted writing of the installed state file.
 *
 * {@see InstalledStateProvider} for test case data providers.
 */
final class InstalledStateTest extends TestCase
{
    private const array LOCAL = ['version' => '1.0.0', 'file' => './vendor/acme/theme'];
    private const array REGISTRY = [
        'version' => '1.3.0',
        'resolved' => 'https://registry.test/left-pad/-/left-pad-1.3.0.tgz',
        'integrity' => 'sha512-AAAA',
    ];

    private string $path = '';
    private string $root = '';

    public function testReadHidesMalformedWarningAtNormalVerbosity(): void
    {
        $io = new BufferIO();

        file_put_contents($this->path, '{');

        self::assertSame(
            [],
            (new InstalledState($this->path, $io))->read(),
            'A malformed file must read as empty.',
        );
        self::assertSame(
            '',
            $io->getOutput(),
            'The warning must be verbose only.',
        );
    }

    #[DataProviderExternal(InstalledStateProvider::class, 'malformedFiles')]
    public function testReadIgnoresMalformedFile(string $contents): void
    {
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);

        file_put_contents($this->path, $contents);

        self::assertSame(
            [],
            (new InstalledState($this->path, $io))->read(),
            'A malformed file must read as empty.',
        );
        self::assertSame(
            "<warning>The installed state file \"{$this->path}\" is malformed and was ignored.</warning>" . PHP_EOL,
            $io->getOutput(),
            'The warning must name the file.',
        );
    }

    public function testReadReturnsEmptyStateForMissingFile(): void
    {
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);

        self::assertSame(
            [],
            (new InstalledState($this->path, $io))->read(),
            'A missing file must read as empty.',
        );
        self::assertSame(
            '',
            $io->getOutput(),
            'A missing file is not malformed.',
        );
    }

    public function testReadReturnsWrittenEntries(): void
    {
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);
        $state = new InstalledState($this->path, $io);

        $state->write(['left-pad' => self::REGISTRY, '@acme/theme' => self::LOCAL]);

        self::assertSame(
            ['@acme/theme' => self::LOCAL, 'left-pad' => self::REGISTRY],
            $state->read(),
            'Both entry shapes must be read back in name order.',
        );
        self::assertSame(
            '',
            $io->getOutput(),
            'A well-formed file must not be reported.',
        );
    }

    public function testWriteRecordsEmptyStateAsObject(): void
    {
        (new InstalledState($this->path, new BufferIO()))->write([]);

        self::assertStringEqualsFile(
            $this->path,
            <<<'JSON'
            {
                "packages": {}
            }

            JSON,
            'An empty state must be written as an empty object.',
        );
    }

    public function testWriteSortsPackagesByName(): void
    {
        (new InstalledState($this->path, new BufferIO()))->write(
            ['left-pad' => self::REGISTRY, '@acme/theme' => self::LOCAL],
        );

        self::assertStringEqualsFile(
            $this->path,
            <<<'JSON'
            {
                "packages": {
                    "@acme/theme": {
                        "version": "1.0.0",
                        "file": "./vendor/acme/theme"
                    },
                    "left-pad": {
                        "version": "1.3.0",
                        "resolved": "https://registry.test/left-pad/-/left-pad-1.3.0.tgz",
                        "integrity": "sha512-AAAA"
                    }
                }
            }

            JSON,
            'Names must be sorted byte-wise.',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/' . uniqid('foxy_installed_state_test_', true);
        $this->path = "{$this->root}/.foxy-installed.json";

        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->removeDirectory($this->root);
    }
}
