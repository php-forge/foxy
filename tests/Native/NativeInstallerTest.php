<?php

declare(strict_types=1);

namespace Foxy\Tests\Native;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Native\{DependencyResolver, NativeInstaller, PackageMetadata, TarballExtractor};
use Foxy\Tests\Fixtures\Native\InMemoryRegistry;
use Foxy\Tests\Provider\NativeInstallerProvider;
use Foxy\Tests\Support\TarArchive;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function is_link;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function rename;
use function str_replace;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Unit tests for {@see NativeInstaller} manifest reading, lock reuse, extraction, local copies, and pruning.
 *
 * {@see NativeInstallerProvider} for test case data providers.
 */
final class NativeInstallerTest extends TestCase
{
    private const string LOCAL_NAME = '@composer-asset/acme--theme';
    private const string LOCAL_PATH = './vendor/php-forge/composer-asset/acme/theme';
    private const array README = [
        'This file locks the frontend dependencies installed by the Foxy native manager.',
        'Do not edit it manually; composer install and composer update regenerate it.',
    ];

    /**
     * @var array<string, string> Integrity values keyed by tarball URL.
     */
    private array $integrities = [];

    /**
     * @var list<string> Lines written through `IOInterface::write()` by the last installation.
     */
    private array $output = [];
    private string $root = '';

    public function testInstallAcceptsEmptyDependencyMaps(): void
    {
        $this->writeJson('package.json', '{"dependencies": [], "devDependencies": {}}');
        $this->install($this->registry());

        self::assertSame(
            ['' => []],
            $this->lock()['requirements'],
            'Empty maps must yield empty root requirements.',
        );
    }

    public function testInstallCopiesLocalPackageAndResolvesItsDependencies(): void
    {
        $registry = new InMemoryRegistry();

        $this->publish($registry, 'bootstrap', ['5.3.8' => []]);
        $this->publish($registry, '@popperjs/core', ['2.11.8' => []]);

        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal(
            [
                'version' => '1.0.0',
                'dependencies' => ['bootstrap' => '^5.3'],
                'peerDependencies' => ['@popperjs/core' => '^2.11'],
                'peerDependenciesMeta' => ['@popperjs/core' => ['optional' => true]],
                'optionalDependencies' => ['missing-pkg' => '^1.0'],
            ],
        );

        $io = $this->io();

        $io
            ->expects(self::once())
            ->method('writeError')
            ->with(
                '<warning>The optional dependency "missing-pkg" (^1.0) was skipped: '
                . Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('missing-pkg', 'memory') . '</warning>',
            );

        $this->installer($registry, $io)->install(
            "{$this->root}/package.json",
            "{$this->root}/foxy.lock",
            "{$this->root}/node_modules",
            false,
        );

        self::assertJsonFileEqualsJsonFile(
            "{$this->root}/vendor/php-forge/composer-asset/acme/theme/package.json",
            "{$this->root}/node_modules/@composer-asset/acme--theme/package.json",
            'The local package must be copied.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/bootstrap/index.js",
            'The local dependency must be installed.',
        );
        self::assertDirectoryDoesNotExist(
            "{$this->root}/node_modules/@popperjs",
            'An optional peer must not be installed.',
        );
        self::assertSame(
            [
                '' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH],
                self::LOCAL_NAME => ['bootstrap' => '^5.3', 'missing-pkg' => '^1.0'],
            ],
            $this->lock()['requirements'],
            'Requirements must list the root and the local source.',
        );
        self::assertSame(
            [
                self::LOCAL_NAME => ['version' => '1.0.0', 'file' => self::LOCAL_PATH],
                'bootstrap' => $this->lockEntry('bootstrap', '5.3.8'),
            ],
            $this->lock()['packages'],
            'The local entry must keep the original file value.',
        );
        self::assertSame(
            [
                '  - Installing @composer-asset/acme--theme (1.0.0): Copying from ' . self::LOCAL_PATH,
                '  - Installing bootstrap (5.3.8): Extracting archive',
            ],
            $this->output,
            'Output must report the copy and the extraction.',
        );
    }

    public function testInstallCreatesScopeDirectoryBeforeMovingPackage(): void
    {
        $registry = $this->registry();
        $fs = $this->getMockBuilder(Filesystem::class)->onlyMethods(['rename'])->getMock();

        $fs
            ->expects(self::once())
            ->method('rename')
            ->willReturnCallback(
                static function (string $source, string $target): void {
                    self::assertDirectoryExists(dirname($target), 'The scope directory must exist before the move.');

                    rename($source, $target);
                },
            );

        $this->writeJson('package.json', ['dependencies' => ['@popperjs/core' => '^2.11']]);

        $io = $this->io();

        (new NativeInstaller($io, $fs, $registry, new DependencyResolver($registry, $io), new TarballExtractor($fs)))
            ->install("{$this->root}/package.json", "{$this->root}/foxy.lock", "{$this->root}/node_modules", false);

        self::assertFileExists(
            "{$this->root}/node_modules/@popperjs/core/index.js",
            'The scoped package must be moved into place.',
        );
    }

    public function testInstallExtractsResolvedPackagesIntoNodeModules(): void
    {
        $registry = $this->registry();

        $this->writeJson(
            'package.json',
            ['dependencies' => ['bootstrap' => '^5.3'], 'devDependencies' => ['left-pad' => '*']],
        );

        $this->install($registry);

        self::assertStringEqualsFile(
            "{$this->root}/node_modules/bootstrap/index.js",
            'bootstrap@5.3.8',
            'The highest matching bootstrap must be extracted.',
        );
        self::assertStringEqualsFile(
            "{$this->root}/node_modules/@popperjs/core/index.js",
            '@popperjs/core@2.11.8',
            'The scoped peer must be extracted under its scope.',
        );
        self::assertStringEqualsFile(
            "{$this->root}/node_modules/left-pad/index.js",
            'left-pad@1.3.0',
            'Development dependencies must be installed.',
        );
        self::assertDirectoryDoesNotExist(
            "{$this->root}/node_modules/.foxy-tmp",
            'The temporary directory must be removed.',
        );
        self::assertSame(
            [
                '_readme' => self::README,
                'requirements' => ['' => ['bootstrap' => '^5.3', 'left-pad' => '*']],
                'packages' => [
                    '@popperjs/core' => $this->lockEntry('@popperjs/core', '2.11.8'),
                    'bootstrap' => $this->lockEntry('bootstrap', '5.3.8'),
                    'left-pad' => $this->lockEntry('left-pad', '1.3.0'),
                ],
            ],
            $this->lock(),
            'The lock file must record the resolution.',
        );
        self::assertSame(
            [
                '  - Installing @popperjs/core (2.11.8): Extracting archive',
                '  - Installing bootstrap (5.3.8): Extracting archive',
                '  - Installing left-pad (1.3.0): Extracting archive',
            ],
            $this->output,
            'Output must list one line per package in name order.',
        );
    }

    public function testInstallIgnoresLeftoverTemporaryDirectory(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*']]);
        $this->writeFile('node_modules/.foxy-tmp/package/stale.js', 'stale');

        $this->install($this->registry());

        self::assertDirectoryDoesNotExist(
            "{$this->root}/node_modules/.foxy-tmp",
            'The leftover directory must be removed.',
        );
        self::assertFileDoesNotExist(
            "{$this->root}/node_modules/left-pad/stale.js",
            'Leftover files must not reach the package.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/left-pad/index.js",
            'The package must be extracted.',
        );
    }

    public function testInstallOrdersLocalPackagesAmongRegistryPackages(): void
    {
        $this->writeJson('web/package.json', ['dependencies' => ['theme' => 'file:../vendor/acme/theme']]);
        $this->writeJson('vendor/acme/theme/package.json', ['dependencies' => ['bootstrap' => '5.2.0']]);

        $this->installer($this->registry(), $this->io())->install(
            "{$this->root}/web/package.json",
            "{$this->root}/web/foxy.lock",
            "{$this->root}/web/node_modules",
            false,
        );

        self::assertFileExists(
            "{$this->root}/web/node_modules/theme/package.json",
            'The relative file value must resolve against the manifest directory.',
        );
        self::assertSame(
            [
                '  - Installing bootstrap (5.2.0): Extracting archive',
                '  - Installing theme (0.0.0): Copying from ../vendor/acme/theme',
            ],
            $this->output,
            'Packages must install in name order; a missing version reads `0.0.0`.',
        );
    }

    public function testInstallPrunesEntriesOutsideTheInstallSet(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['bootstrap' => '^5.3']]);
        $this->writeFile('node_modules/.bin/x', 'x');
        $this->writeFile('node_modules/@old/pkg/index.js', 'old');
        $this->writeFile('node_modules/@stray', 'stray');
        $this->writeFile('node_modules/bootstrap/extra.txt', 'extra');
        $this->writeFile('node_modules/keep.txt', 'keep');
        $this->writeFile('node_modules/stale/index.js', 'stale');

        $this->install($this->registry());

        self::assertSame(
            [
                '  - Installing @popperjs/core (2.11.8): Extracting archive',
                '  - Installing bootstrap (5.3.8): Extracting archive',
                '  - Removing @old/pkg',
                '  - Removing @stray',
                '  - Removing keep.txt',
                '  - Removing stale',
            ],
            $this->output,
            'Every extraneous entry must be reported.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/.bin/x",
            'Dot-entries must be kept.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/@popperjs/core/index.js",
            'A scope holding an installed package must be kept.',
        );

        foreach (['@old', '@stray', 'bootstrap/extra.txt', 'keep.txt', 'stale'] as $entry) {
            self::assertFileDoesNotExist(
                "{$this->root}/node_modules/{$entry}",
                "Entry {$entry} must be gone.",
            );
        }
    }

    public function testInstallRemovesDanglingSymbolicLinks(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*']]);
        $this->writeFile('node_modules/.keep', '');

        if (
            !@symlink("{$this->root}/missing", "{$this->root}/node_modules/stale-link")
            || !@symlink("{$this->root}/missing", "{$this->root}/node_modules/left-pad")
        ) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        $this->install($this->registry());

        self::assertFalse(
            is_link("{$this->root}/node_modules/stale-link"),
            'An extraneous dangling link must be removed.',
        );
        self::assertFalse(
            is_link("{$this->root}/node_modules/left-pad"),
            'A dangling link in place of a package must be replaced.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/left-pad/index.js",
            'The package must be extracted in place of the link.',
        );
        self::assertSame(
            ['  - Installing left-pad (1.3.0): Extracting archive', '  - Removing stale-link'],
            $this->output,
            'The extraneous link must be reported.',
        );
    }

    public function testInstallResolvesAgainWhenLocalVersionChanges(): void
    {
        $registry = $this->registry();

        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal(['version' => '1.0.0', 'dependencies' => ['left-pad' => '*']]);
        $this->install($registry);
        $this->writeLocal(['version' => '1.1.0', 'dependencies' => ['left-pad' => '*']]);
        $this->install($registry);

        self::assertSame(
            ['left-pad', 'left-pad'],
            $registry->requested,
            'A new local version must trigger a resolution.',
        );
        self::assertSame(
            ['version' => '1.1.0', 'file' => self::LOCAL_PATH],
            $this->lock()['packages'][self::LOCAL_NAME],
            'The lock file must record the new local version.',
        );
    }

    public function testInstallResolvesAgainWhenLockLacksLocalPackage(): void
    {
        $registry = $this->registry();

        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal(['version' => '1.0.0', 'dependencies' => ['left-pad' => '*']]);
        $this->install($registry);

        $lock = $this->lock();

        unset($lock['packages'][self::LOCAL_NAME]);

        $this->writeJson('foxy.lock', $lock);
        $this->install($registry);

        self::assertSame(
            ['left-pad', 'left-pad'],
            $registry->requested,
            'A lock without the local entry is stale.',
        );
    }

    public function testInstallResolvesAgainWhenRootSpecChanges(): void
    {
        $registry = $this->registry();

        $this->writeJson('package.json', ['dependencies' => ['bootstrap' => '5.2.0']]);
        $this->install($registry);
        $this->writeJson('package.json', ['dependencies' => ['bootstrap' => '^5.3']]);
        $this->install($registry);

        self::assertStringEqualsFile(
            "{$this->root}/node_modules/bootstrap/index.js",
            'bootstrap@5.3.8',
            'The new specification must be resolved.',
        );
        self::assertSame(
            ['' => ['bootstrap' => '^5.3']],
            $this->lock()['requirements'],
            'The lock file must record the new specification.',
        );
    }

    public function testInstallResolvesAgainWhenUpdateIsRequested(): void
    {
        $registry = $this->registry();

        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*']]);
        $this->install($registry);

        $compact = $this->compactLock();

        $this->install($registry, true);

        self::assertSame(
            ['left-pad', 'left-pad'],
            $registry->requested,
            'An update must resolve even with a fresh lock.',
        );
        self::assertNotSame(
            $compact,
            file_get_contents("{$this->root}/foxy.lock"),
            'An update must rewrite the lock file.',
        );
    }

    public function testInstallReusesFreshLockWithoutMetadataRequests(): void
    {
        $registry = $this->registry();

        $this->writeJson(
            'package.json',
            ['dependencies' => ['left-pad' => '*', self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]],
        );
        $this->writeLocal(
            [
                'version' => '1.0.0',
                'dependencies' => ['bootstrap' => '^5.3'],
                'peerDependencies' => ['@popperjs/core' => '^2.11'],
            ],
        );
        $this->install($registry);

        $requested = $registry->requested;
        $fetched = count($registry->fetched);
        $compact = $this->compactLock();

        $this->install($registry);

        self::assertSame(
            $requested,
            $registry->requested,
            'A fresh lock must not request metadata.',
        );
        self::assertCount(
            2 * $fetched,
            $registry->fetched,
            'Locked tarballs must be fetched again.',
        );
        self::assertStringEqualsFile(
            "{$this->root}/foxy.lock",
            $compact,
            'A fresh lock must not be rewritten.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/@popperjs/core/index.js",
            'Locked packages must be installed.',
        );
    }

    public function testInstallTreatsMissingManifestAsEmpty(): void
    {
        $this->install($this->registry());

        self::assertSame(
            ['_readme' => self::README, 'requirements' => ['' => []], 'packages' => []],
            $this->lock(),
            'The lock file must hold empty requirements.',
        );
        self::assertDirectoryExists(
            "{$this->root}/node_modules",
            'The modules directory must be created.',
        );
        self::assertSame(
            [],
            $this->output,
            'Nothing must be reported.',
        );
    }

    public function testInstallUpdateOverwritesMalformedLock(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*']]);
        $this->writeFile('foxy.lock', '{');

        $this->install($this->registry(), true);

        self::assertSame(
            ['' => ['left-pad' => '*']],
            $this->lock()['requirements'],
            'The malformed lock must be replaced.',
        );
    }

    public function testInstallUsesAbsoluteFileValue(): void
    {
        $directory = "{$this->root}/elsewhere/theme";

        $this->writeJson('package.json', ['dependencies' => ['theme' => "file:{$directory}"]]);
        $this->writeJson('elsewhere/theme/package.json', ['version' => '2.0.0']);

        $this->install($this->registry());

        self::assertFileExists(
            "{$this->root}/node_modules/theme/package.json",
            'An absolute directory must be copied.',
        );
        self::assertSame(
            ['theme' => ['version' => '2.0.0', 'file' => $directory]],
            $this->lock()['packages'],
            'The lock file must keep the absolute value.',
        );
    }

    #[DataProviderExternal(NativeInstallerProvider::class, 'invalidDependencyMaps')]
    public function testThrowRuntimeExceptionForInvalidDependencyMap(string $manifest, string $field): void
    {
        $this->writeJson('package.json', $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_MANIFEST_DEPENDENCIES_INVALID->getMessage($field, "{$this->root}/package.json"),
        );

        $this->install($this->registry());
    }

    public function testThrowRuntimeExceptionForInvalidLocalDependencyMap(): void
    {
        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal(['peerDependencies' => ['bootstrap']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_MANIFEST_DEPENDENCIES_INVALID->getMessage(
                'peerDependencies',
                $this->localDirectory() . '/package.json',
            ),
        );

        $this->install($this->registry());
    }

    public function testThrowRuntimeExceptionForLocalFileDependency(): void
    {
        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal(['optionalDependencies' => ['nested' => 'file:../nested']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_SPEC_UNSUPPORTED->getMessage('file:../nested', 'nested'),
        );

        $this->install($this->registry());
    }

    #[DataProviderExternal(NativeInstallerProvider::class, 'mandatoryLocalFields')]
    public function testThrowRuntimeExceptionForUnresolvableLocalRequirement(string $field): void
    {
        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal([$field => ['missing-pkg' => '^1.0']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('missing-pkg', 'memory'),
        );

        $this->install($this->registry());
    }

    public function testThrowRuntimeExceptionWhenLocalManifestIsMissing(): void
    {
        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_LOCAL_PACKAGE_MISSING->getMessage(
                $this->localDirectory(),
                self::LOCAL_NAME,
            ),
        );

        $this->install($this->registry());
    }

    public function testThrowRuntimeExceptionWhenLocalPackageCannotBeCopied(): void
    {
        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal(['version' => '1.0.0']);

        $fs = $this->getMockBuilder(Filesystem::class)->onlyMethods(['copy'])->getMock();

        $fs
            ->method('copy')
            ->willReturn(false);

        $registry = new InMemoryRegistry();
        $io = $this->io();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_PACKAGE_COPY_FAILED->getMessage(
                $this->localDirectory(),
                "{$this->root}/node_modules/@composer-asset/acme--theme",
            ),
        );

        (new NativeInstaller($io, $fs, $registry, new DependencyResolver($registry, $io), new TarballExtractor($fs)))
            ->install("{$this->root}/package.json", "{$this->root}/foxy.lock", "{$this->root}/node_modules", false);
    }

    public function testThrowRuntimeExceptionWhenLockIsMalformed(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*']]);
        $this->writeFile('foxy.lock', '{');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_LOCK_INVALID->getMessage("{$this->root}/foxy.lock"),
        );

        $this->install($this->registry());
    }

    public function testThrowRuntimeExceptionWhenPackageDirectoryCannotBeRemoved(): void
    {
        $this->writeFile('node_modules/stale/index.js', 'stale');

        $fs = $this->createMock(Filesystem::class);

        $fs
            ->method('remove')
            ->willReturn(false);

        $registry = new InMemoryRegistry();
        $installer = new NativeInstaller(
            $this->io(),
            $fs,
            $registry,
            new DependencyResolver($registry, $this->io()),
            new TarballExtractor(new Filesystem()),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_PACKAGE_REMOVE_FAILED->getMessage("{$this->root}/node_modules/stale"),
        );

        $installer->install(
            "{$this->root}/package.json",
            "{$this->root}/foxy.lock",
            "{$this->root}/node_modules",
            false,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/' . uniqid('foxy_native_installer_test_', true);

        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->removeDirectory($this->root);
    }

    /**
     * Rewrites the lock file as compact JSON and returns its bytes, so a later rewrite is detectable.
     */
    private function compactLock(): string
    {
        $path = "{$this->root}/foxy.lock";
        $compact = json_encode(
            json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        file_put_contents($path, $compact);

        return $compact;
    }

    private function install(InMemoryRegistry $registry, bool $update = false): void
    {
        $this->installer($registry, $this->io())->install(
            "{$this->root}/package.json",
            "{$this->root}/foxy.lock",
            "{$this->root}/node_modules",
            $update,
        );
    }

    private function installer(InMemoryRegistry $registry, IOInterface $io): NativeInstaller
    {
        $fs = new Filesystem();

        return new NativeInstaller($io, $fs, $registry, new DependencyResolver($registry, $io), new TarballExtractor($fs));
    }

    /**
     * Returns an output mock that records the written lines into {@see $output}.
     */
    private function io(): IOInterface&MockObject
    {
        $this->output = [];

        $io = $this->createMock(IOInterface::class);

        $io
            ->method('write')
            ->willReturnCallback(
                function (string $message): void {
                    $this->output[] = $message;
                },
            );

        return $io;
    }

    /**
     * Returns the local package directory as the installer resolves it: the `file:` path joined to the manifest
     * directory with `/` and normalized.
     */
    private function localDirectory(): string
    {
        return (new Filesystem())->normalizePath("{$this->root}/" . self::LOCAL_PATH);
    }

    /**
     * @return array<string, mixed>
     */
    private function lock(): array
    {
        return json_decode((string) file_get_contents("{$this->root}/foxy.lock"), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{version: string, resolved: string, integrity: string}
     */
    private function lockEntry(string $name, string $version): array
    {
        $url = self::tarballUrl($name, $version);

        return ['version' => $version, 'resolved' => $url, 'integrity' => $this->integrities[$url]];
    }

    /**
     * Registers the metadata and the tarballs of a package; every tarball holds `package.json` and `index.js`.
     *
     * @param array<string, array<string, mixed>> $versions Version entries without `dist`, keyed by version.
     */
    private function publish(InMemoryRegistry $registry, string $name, array $versions): void
    {
        $entries = [];

        foreach ($versions as $version => $entry) {
            $url = self::tarballUrl($name, $version);
            $bytes = TarArchive::create()
                ->addFile('package/package.json', (string) json_encode(['name' => $name, 'version' => $version]))
                ->addFile('package/index.js', "{$name}@{$version}")
                ->gzip();

            $this->integrities[$url] = 'sha512-' . base64_encode(hash('sha512', $bytes, true));

            $registry->addTarball($url, $bytes);

            $entries[$version] = $entry + ['dist' => ['tarball' => $url, 'integrity' => $this->integrities[$url]]];
        }

        $registry->add(PackageMetadata::fromDocument($name, ['versions' => $entries]));
    }

    /**
     * Returns a registry with bootstrap 5.3.8 (peer `@popperjs/core`) and 5.2.0, `@popperjs/core`, and `left-pad`.
     */
    private function registry(): InMemoryRegistry
    {
        $registry = new InMemoryRegistry();

        $this->publish(
            $registry,
            'bootstrap',
            ['5.3.8' => ['peerDependencies' => ['@popperjs/core' => '^2.11']], '5.2.0' => []],
        );
        $this->publish($registry, '@popperjs/core', ['2.11.8' => []]);
        $this->publish($registry, 'left-pad', ['1.3.0' => []]);

        return $registry;
    }

    private static function tarballUrl(string $name, string $version): string
    {
        return "https://registry.test/{$name}/-/" . str_replace(['@', '/'], ['', '-'], $name) . "-{$version}.tgz";
    }

    private function writeFile(string $path, string $contents): void
    {
        $file = "{$this->root}/{$path}";

        (new Filesystem())->ensureDirectoryExists(dirname($file));

        file_put_contents($file, $contents);
    }

    /**
     * @param array<string, mixed>|string $data Manifest data, or raw JSON.
     */
    private function writeJson(string $path, array|string $data): void
    {
        $this->writeFile(
            $path,
            is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param array<string, mixed> $manifest Local package manifest.
     */
    private function writeLocal(array $manifest): void
    {
        $this->writeJson('vendor/php-forge/composer-asset/acme/theme/package.json', $manifest);
    }
}
