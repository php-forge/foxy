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

use function array_keys;
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
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Unit tests for {@see NativeInstaller} manifest reading, lock reuse, extraction, local copies, and pruning.
 *
 * {@see NativeInstallerProvider} for test case data providers.
 */
final class NativeInstallerTest extends TestCase
{
    private const string ALPHA_NAME = '@composer-asset/acme--alpha';
    private const string ALPHA_PATH = './vendor/php-forge/composer-asset/acme/alpha';
    private const string LOCAL_NAME = '@composer-asset/acme--theme';
    private const string LOCAL_PATH = './vendor/php-forge/composer-asset/acme/theme';
    private const string NOTHING = 'Nothing to install, update or remove';
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
            ['' => self::kinds([])],
            $this->lock()['requirements'],
            'Empty maps must yield empty root requirements.',
        );
    }

    public function testInstallAcceptsLocalPackagesSharingAPrefixWithInstallDirectory(): void
    {
        $this->writeJson(
            'package.json',
            ['dependencies' => ['lib' => 'file:./lib', 'lib-modules-src' => 'file:./lib-modules-src']],
        );
        $this->writeJson('lib/package.json', ['version' => '1.0.0']);
        $this->writeJson('lib-modules-src/package.json', ['version' => '1.0.0']);

        $this->installInto("{$this->root}/lib-modules");

        self::assertFileExists(
            "{$this->root}/lib-modules/lib-modules-src/package.json",
            'A sibling with a longer name must not count as an overlap.',
        );
        self::assertFileExists(
            "{$this->root}/lib-modules/lib/package.json",
            'A sibling with a shorter name must not count as an overlap.',
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
                '' => self::kinds([self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]),
                self::LOCAL_NAME => self::localKinds('1.0.0', ['bootstrap' => '^5.3'], ['missing-pkg' => '^1.0']),
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

    public function testInstallCopiesRootOptionalLocalPackage(): void
    {
        $this->writeJson('package.json', ['optionalDependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal(['version' => '1.0.0', 'dependencies' => ['left-pad' => '*']]);
        $this->install($this->registry());

        self::assertFileExists(
            "{$this->root}/node_modules/" . self::LOCAL_NAME . '/package.json',
            'The optional local package must be copied.',
        );
        self::assertSame(
            [self::LOCAL_NAME, 'left-pad'],
            array_keys($this->lock()['packages']),
            'The local package and its dependency must be locked.',
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

    public function testInstallDropsRootOptionalLocalPackageWithUnavailableDependency(): void
    {
        $this->writeJson(
            'package.json',
            [
                'dependencies' => ['left-pad' => '*'],
                'optionalDependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH],
            ],
        );
        $this->writeLocal(['version' => '1.0.0', 'dependencies' => ['missing-pkg' => '^1.0']]);

        $io = $this->io();

        $io
            ->expects(self::once())
            ->method('writeError')
            ->with(
                '<warning>The optional dependency "' . self::LOCAL_NAME . '" (file:' . self::LOCAL_PATH . ') was skipped: '
                . Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('missing-pkg', 'memory') . '</warning>',
            );

        $this->installer($this->registry(), $io)->install(
            "{$this->root}/package.json",
            "{$this->root}/foxy.lock",
            "{$this->root}/node_modules",
            false,
        );

        self::assertDirectoryDoesNotExist(
            "{$this->root}/node_modules/" . self::LOCAL_NAME,
            'The dropped local package must not be copied.',
        );
        self::assertSame(
            ['left-pad'],
            array_keys($this->lock()['packages']),
            'The dropped local package must not be locked.',
        );
        self::assertSame(
            [
                '' => self::kinds(['left-pad' => '*'], [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]),
                self::LOCAL_NAME => self::localKinds('1.0.0', ['missing-pkg' => '^1.0']),
            ],
            $this->lock()['requirements'],
            'The fingerprint must keep the manifest content.',
        );
    }

    public function testInstallExtractsAgainPackageReplacedBySymbolicLink(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*']]);
        $this->install($this->registry());
        $this->writeFile('external/index.js', 'external');
        $this->removePath('node_modules/left-pad');

        if (!@symlink("{$this->root}/external", "{$this->root}/node_modules/left-pad")) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        $this->install($this->registry());

        self::assertSame(
            ['  - Installing left-pad (1.3.0): Extracting archive'],
            $this->output,
            'A link must not count as the installed directory.',
        );
        self::assertFalse(
            is_link("{$this->root}/node_modules/left-pad"),
            'The link must be replaced by a directory.',
        );
        self::assertStringEqualsFile(
            "{$this->root}/external/index.js",
            'external',
            'The link target must stay intact.',
        );
    }

    public function testInstallExtractsAgainPackageWhoseDirectoryIsMissing(): void
    {
        $registry = $this->registry();

        $this->writeJson('package.json', ['dependencies' => ['bootstrap' => '^5.3']]);
        $this->install($registry);
        $this->removePath('node_modules/bootstrap');
        $this->install($registry);

        self::assertSame(
            ['  - Installing bootstrap (5.3.8): Extracting archive'],
            $this->output,
            'Only the missing package must be extracted.',
        );
        self::assertSame(
            ['bootstrap'],
            $registry->prefetched[1]['names'],
            'Only the missing package must be prefetched.',
        );
    }

    public function testInstallExtractsEverythingAgainWhenStateIsMalformed(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*']]);
        $this->install($this->registry());
        $this->writeFile('node_modules/.foxy-installed.json', '{');

        $io = $this->io(IOInterface::VERBOSE);

        $io
            ->expects(self::once())
            ->method('writeError')
            ->with(
                '<warning>The installed state file "' . "{$this->root}/node_modules/.foxy-installed.json"
                . '" is malformed and was ignored.</warning>',
                true,
                IOInterface::VERBOSE,
            );

        $this->installer($this->registry(), $io)->install(
            "{$this->root}/package.json",
            "{$this->root}/foxy.lock",
            "{$this->root}/node_modules",
            false,
        );

        self::assertSame(
            ['  - Installing left-pad (1.3.0): Extracting archive'],
            $this->output,
            'A malformed state must keep nothing.',
        );
        self::assertSame(
            ['packages' => ['left-pad' => $this->lockEntry('left-pad', '1.3.0')]],
            $this->state(),
            'The malformed state must be replaced.',
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
                'requirements' => ['' => self::kinds(['bootstrap' => '^5.3', 'left-pad' => '*'])],
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

    public function testInstallKeepsSymlinkedInstallDirectory(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*']]);
        $this->writeFile('real-modules/.keep', '');

        if (!@symlink("{$this->root}/real-modules", "{$this->root}/modules")) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        $this->installInto("{$this->root}/modules");

        self::assertTrue(
            is_link("{$this->root}/modules"),
            'The install directory link must be kept.',
        );
        self::assertFileExists(
            "{$this->root}/real-modules/left-pad/index.js",
            'The package must be installed through the link.',
        );
    }

    public function testInstallKeepsUnchangedRegistryPackages(): void
    {
        $registry = $this->registry();

        $this->writeJson(
            'package.json',
            ['dependencies' => ['bootstrap' => '^5.3'], 'devDependencies' => ['left-pad' => '*']],
        );
        $this->install($registry);
        $this->writeFile('node_modules/left-pad/marker.txt', 'marker');
        $this->install($registry);

        self::assertSame(
            [self::NOTHING],
            $this->output,
            'Kept packages must stay silent at normal verbosity.',
        );

        $this->install($registry, false, IOInterface::VERBOSE);

        self::assertSame(
            [
                '  - Keeping @popperjs/core (2.11.8)',
                '  - Keeping bootstrap (5.3.8)',
                '  - Keeping left-pad (1.3.0)',
                self::NOTHING,
            ],
            $this->output,
            'Each kept package must be listed at verbose level.',
        );
        self::assertCount(
            3,
            $registry->fetched,
            'Kept tarballs must not be fetched.',
        );
        self::assertCount(
            1,
            $registry->prefetched,
            'Nothing must be prefetched when every package is kept.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/left-pad/marker.txt",
            'A kept directory must not be replaced.',
        );
        self::assertSame(
            [
                'packages' => [
                    '@popperjs/core' => $this->lockEntry('@popperjs/core', '2.11.8'),
                    'bootstrap' => $this->lockEntry('bootstrap', '5.3.8'),
                    'left-pad' => $this->lockEntry('left-pad', '1.3.0'),
                ],
            ],
            $this->state(),
            'The state must still list the kept packages.',
        );
    }

    public function testInstallLetsLocalOptionalDependencyOverrideMandatoryOne(): void
    {
        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal(
            [
                'version' => '1.0.0',
                'dependencies' => ['bootstrap' => '5.2.0', 'missing-pkg' => '^1.0'],
                'peerDependencies' => ['missing-pkg' => '^1.0'],
                'optionalDependencies' => ['bootstrap' => '^5.3', 'missing-pkg' => '^2.0'],
            ],
        );

        $this->installWithOptionalWarning('missing-pkg', '^2.0');

        self::assertStringEqualsFile(
            "{$this->root}/node_modules/bootstrap/index.js",
            'bootstrap@5.3.8',
            'The optional specification must be resolved.',
        );
        self::assertSame(
            [
                '' => self::kinds([self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]),
                self::LOCAL_NAME => self::localKinds('1.0.0', [], ['bootstrap' => '^5.3', 'missing-pkg' => '^2.0']),
            ],
            $this->lock()['requirements'],
            'The local requirements must record the optional specifications.',
        );
    }

    public function testInstallLetsRootOptionalDependencyOverrideMandatoryOne(): void
    {
        $this->writeJson(
            'package.json',
            [
                'dependencies' => ['bootstrap' => '^4.0'],
                'devDependencies' => ['left-pad' => '*'],
                'optionalDependencies' => ['missing-pkg' => '^1.0', 'bootstrap' => '^5.3'],
            ],
        );

        $this->installWithOptionalWarning('missing-pkg', '^1.0');

        self::assertStringEqualsFile(
            "{$this->root}/node_modules/bootstrap/index.js",
            'bootstrap@5.3.8',
            'The optional specification must be resolved.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/left-pad/index.js",
            'The remaining requirements must be installed.',
        );
        self::assertSame(
            ['' => self::kinds(['left-pad' => '*'], ['bootstrap' => '^5.3', 'missing-pkg' => '^1.0'])],
            $this->lock()['requirements'],
            'The optional entries must leave the mandatory map, both sorted.',
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

    public function testInstallPrefetchesRegistryPackagesOnceBeforeFetching(): void
    {
        $registry = $this->registry();

        $this->writeJson(
            'package.json',
            ['dependencies' => ['left-pad' => '*', self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]],
        );
        $this->writeLocal(['version' => '1.0.0', 'dependencies' => ['bootstrap' => '^5.3']]);
        $this->install($registry);

        self::assertSame(
            [
                [
                    'names' => ['@popperjs/core', 'bootstrap', 'left-pad'],
                    'directory' => "{$this->root}/node_modules/.foxy-tmp",
                    'fetchedBefore' => 0,
                ],
            ],
            $registry->prefetched,
            'One prefetch must list every registry package, without the local one, before any fetch.',
        );
        self::assertCount(
            3,
            $registry->fetched,
            'Every prefetched tarball must still be fetched.',
        );
    }

    #[DataProviderExternal(NativeInstallerProvider::class, 'staleEntries')]
    public function testInstallPrunesEntriesOnWarmInstall(string $file, string $line): void
    {
        $registry = $this->registry();

        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*']]);
        $this->install($registry);
        $this->writeFile("node_modules/{$file}", 'stale');
        $this->install($registry);

        self::assertSame(
            [$line],
            $this->output,
            'A removal alone must count as a change.',
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

    public function testInstallRecordsInstallSetInStateFile(): void
    {
        $this->writeJson(
            'package.json',
            ['dependencies' => ['left-pad' => '*', self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]],
        );
        $this->writeLocal(['version' => '1.0.0']);
        $this->writeFile('node_modules/stale/index.js', 'stale');
        $this->install($this->registry());

        self::assertSame(
            [
                'packages' => [
                    self::LOCAL_NAME => ['version' => '1.0.0', 'file' => self::LOCAL_PATH],
                    'left-pad' => $this->lockEntry('left-pad', '1.3.0'),
                ],
            ],
            $this->state(),
            'The state must hold the lock entry of every installed package.',
        );
        self::assertSame(
            $this->lock()['packages'],
            $this->state()['packages'],
            'The state entries must match the lock entries.',
        );
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

    public function testInstallRemovesStateFileBeforeChangingPackages(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*']]);
        $this->install($this->registry());
        $this->removePath('node_modules/left-pad');

        try {
            $this->install(new InMemoryRegistry());

            self::fail('Expected the missing tarball to abort the installation.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::NATIVE_REGISTRY_REQUEST_FAILED->getMessage(
                    $this->lockEntry('left-pad', '1.3.0')['resolved'],
                    'not registered',
                ),
                $exception->getMessage(),
                'The failed fetch must abort the installation.',
            );
        }

        self::assertFileDoesNotExist(
            "{$this->root}/node_modules/.foxy-installed.json",
            'An interrupted installation must leave no state.',
        );
    }

    public function testInstallRemovesSymlinkedScopesWithoutVisitingTheirTargets(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*']]);
        $this->writeFile('node_modules/.keep', '');
        $this->writeFile('external/pkg/package.json', '{}');
        $this->writeFile('other/index.js', 'other');

        if (
            !@symlink("{$this->root}/external", "{$this->root}/node_modules/@old")
            || !@symlink("{$this->root}/other", "{$this->root}/node_modules/@other")
        ) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        $this->install($this->registry());

        self::assertSame(
            ['  - Installing left-pad (1.3.0): Extracting archive', '  - Removing @old', '  - Removing @other'],
            $this->output,
            'Each link must be removed as one entry.',
        );
        self::assertFalse(
            is_link("{$this->root}/node_modules/@old") || is_link("{$this->root}/node_modules/@other"),
            'The links must be gone.',
        );
        self::assertFileExists(
            "{$this->root}/external/pkg/package.json",
            'The target of a link must stay intact.',
        );
        self::assertFileExists(
            "{$this->root}/other/index.js",
            'The target of a link must stay intact.',
        );
    }

    public function testInstallReplacesScopeFileOfInstalledPackage(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['@popperjs/core' => '^2.11']]);
        $this->writeFile('node_modules/@popperjs', 'stale');

        $this->install($this->registry());

        self::assertSame(
            ['  - Removing @popperjs', '  - Installing @popperjs/core (2.11.8): Extracting archive'],
            $this->output,
            'The scope file must be removed before the installation.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/@popperjs/core/index.js",
            'The package must be extracted into a real scope.',
        );
    }

    public function testInstallReplacesScopeFileOfRefreshedLocalPackage(): void
    {
        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal(['version' => '1.0.0']);
        $this->install($this->registry());
        $this->removePath('node_modules/@composer-asset');
        $this->writeFile('node_modules/@composer-asset', 'stale');
        $this->install($this->registry());

        self::assertSame(
            ['  - Removing @composer-asset'],
            $this->output,
            'The scope removal must count as a change.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/" . self::LOCAL_NAME . '/package.json',
            'The local package must be copied into a real scope.',
        );
    }

    public function testInstallReplacesSymlinkedScopeOfInstalledPackage(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['@popperjs/core' => '^2.11']]);
        $this->writeFile('node_modules/.keep', '');
        $this->writeFile('external/core/package.json', '{"name": "external"}');

        if (!@symlink("{$this->root}/external", "{$this->root}/node_modules/@popperjs")) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        $this->install($this->registry());

        self::assertSame(
            ['  - Removing @popperjs', '  - Installing @popperjs/core (2.11.8): Extracting archive'],
            $this->output,
            'The scope link must be removed before the installation.',
        );
        self::assertFalse(
            is_link("{$this->root}/node_modules/@popperjs"),
            'The scope must become a real directory.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/@popperjs/core/index.js",
            'The package must be extracted into the real scope.',
        );
        self::assertStringEqualsFile(
            "{$this->root}/external/core/package.json",
            '{"name": "external"}',
            'The link target must stay intact.',
        );
    }

    public function testInstallReportsLocalCopiesByRecordedVersion(): void
    {
        $registry = $this->registry();
        $copy = '@composer-asset/acme--theme (%s): Copying from ' . self::LOCAL_PATH;

        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal(['version' => '1.0.0']);
        $this->install($registry);

        self::assertSame(
            ['  - Installing ' . sprintf($copy, '1.0.0')],
            $this->output,
            'A local package without a recorded entry must be reported as installed.',
        );

        $this->writeLocal(['version' => '1.1.0']);
        $this->install($registry);

        self::assertSame(
            ['  - Updating ' . sprintf($copy, '1.0.0 => 1.1.0')],
            $this->output,
            'A new local version must be reported as an update.',
        );

        $this->writeFile(self::LOCAL_PATH . '/index.js', 'changed');
        $this->install($registry);

        self::assertSame(
            [self::NOTHING],
            $this->output,
            'A refreshed copy must stay silent at normal verbosity.',
        );

        $this->install($registry, false, IOInterface::VERBOSE);

        self::assertSame(
            ['  - Refreshing ' . sprintf($copy, '1.1.0'), self::NOTHING],
            $this->output,
            'A refreshed copy must be listed at verbose level.',
        );
        self::assertStringEqualsFile(
            "{$this->root}/node_modules/" . self::LOCAL_NAME . '/index.js',
            'changed',
            'An unchanged version must still be copied again.',
        );
    }

    public function testInstallReportsLocalPackageInstalledWhenRecordedEntryDiffers(): void
    {
        $registry = $this->registry();

        $this->writeJson('package.json', ['dependencies' => ['theme' => 'file:./theme']]);
        $this->writeJson('theme/package.json', ['version' => '1.0.0']);
        $this->install($registry);
        $this->writeJson('package.json', ['dependencies' => ['theme' => 'file:./themes/theme']]);
        $this->writeJson('themes/theme/package.json', ['version' => '1.0.0']);
        $this->install($registry);

        self::assertSame(
            ['  - Installing theme (1.0.0): Copying from ./themes/theme'],
            $this->output,
            'A moved source of the same version must be reported as installed.',
        );
    }

    public function testInstallResolvesAgainWhenDroppedOptionalLocalVersionChanges(): void
    {
        $registry = $this->registry();

        $this->publish($registry, 'widgets', ['1.0.0' => ['dependencies' => [self::LOCAL_NAME => '^2.0']]]);
        $this->publish($registry, self::LOCAL_NAME, ['2.1.0' => []]);
        $this->writeJson(
            'package.json',
            [
                'dependencies' => ['widgets' => '^1.0'],
                'optionalDependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH],
            ],
        );
        $this->writeLocal(['version' => '1.0.0']);
        $this->install($registry);
        $this->writeLocal(['version' => '2.0.0']);
        $this->install($registry);

        self::assertSame(
            ['version' => '2.0.0', 'file' => self::LOCAL_PATH],
            $this->lock()['packages'][self::LOCAL_NAME],
            'The accepted local version must replace the registry package.',
        );
        self::assertJsonStringEqualsJsonFile(
            "{$this->root}/node_modules/" . self::LOCAL_NAME . '/package.json',
            '{"version": "2.0.0"}',
            'The local package must be copied.',
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

    public function testInstallResolvesAgainWhenLockLacksLocalVersion(): void
    {
        $registry = $this->registry();

        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal(['version' => '1.0.0', 'dependencies' => ['left-pad' => '*']]);
        $this->install($registry);

        $lock = $this->lock();

        unset($lock['requirements'][self::LOCAL_NAME]['version']);

        $this->writeJson('foxy.lock', $lock);
        $this->install($registry);

        self::assertSame(
            ['left-pad', 'left-pad'],
            $registry->requested,
            'A local source without its version must make the lock stale.',
        );
    }

    public function testInstallResolvesAgainWhenRootOptionalSpecChanges(): void
    {
        $registry = $this->registry();

        $this->writeJson('package.json', ['optionalDependencies' => ['bootstrap' => '5.2.0']]);
        $this->install($registry);
        $this->writeJson('package.json', ['optionalDependencies' => ['bootstrap' => '^5.3']]);
        $this->install($registry);

        self::assertStringEqualsFile(
            "{$this->root}/node_modules/bootstrap/index.js",
            'bootstrap@5.3.8',
            'The new specification must be resolved.',
        );
        self::assertSame(
            ['' => self::kinds([], ['bootstrap' => '^5.3'])],
            $this->lock()['requirements'],
            'The lock file must record the new specification.',
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
            ['' => self::kinds(['bootstrap' => '^5.3'])],
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

    public function testInstallResolvesLocalPackageWithoutRegistryRequest(): void
    {
        $registry = $this->registry();

        $this->publish($registry, 'widgets', ['1.0.0' => ['dependencies' => [self::LOCAL_NAME => '^1.0']]]);
        $this->writeJson(
            'package.json',
            ['dependencies' => ['widgets' => '^1.0', self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]],
        );
        $this->writeLocal(['version' => '1.2.0']);
        $this->install($registry);

        self::assertSame(
            ['widgets'],
            $registry->requested,
            'The local package must not be fetched.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/widgets/index.js",
            'The dependent package must be installed.',
        );
    }

    public function testInstallReusesFreshLockWithDroppedOptionalLocalPackage(): void
    {
        $registry = $this->registry();

        $this->writeJson(
            'package.json',
            [
                'dependencies' => ['left-pad' => '*'],
                'optionalDependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH],
            ],
        );
        $this->writeLocal(['version' => '1.0.0', 'dependencies' => ['missing-pkg' => '^1.0']]);
        $this->install($registry);

        $requested = $registry->requested;
        $compact = $this->compactLock();

        $this->install($registry);

        self::assertSame(
            $requested,
            $registry->requested,
            'The absent optional local package must not make the lock stale.',
        );
        self::assertStringEqualsFile(
            "{$this->root}/foxy.lock",
            $compact,
            'A fresh lock must not be rewritten.',
        );
        self::assertDirectoryDoesNotExist(
            "{$this->root}/node_modules/" . self::LOCAL_NAME,
            'The dropped local package must stay absent.',
        );
    }

    public function testInstallReusesFreshLockWithLocalPackagesOfBothKinds(): void
    {
        $registry = $this->registry();

        $this->writeJson(
            'package.json',
            [
                'dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH],
                'optionalDependencies' => [
                    'left-pad' => '*',
                    self::ALPHA_NAME => 'file:' . self::ALPHA_PATH,
                    'bootstrap' => '^5.3',
                ],
            ],
        );
        $this->writeLocal(['version' => '1.0.0']);
        $this->writeJson(
            self::ALPHA_PATH . '/package.json',
            ['version' => '2.0.0', 'optionalDependencies' => ['left-pad' => '*', '@popperjs/core' => '^2.11']],
        );
        $this->install($registry);

        $requested = $registry->requested;
        $compact = $this->compactLock();

        $this->install($registry);

        self::assertSame(
            $requested,
            $registry->requested,
            'A fresh lock must not request metadata.',
        );
        self::assertStringEqualsFile(
            "{$this->root}/foxy.lock",
            $compact,
            'A fresh lock must not be rewritten.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/" . self::ALPHA_NAME . '/package.json',
            'The optional local package must be copied.',
        );
    }

    public function testInstallReusesFreshLockWithOptionalLocalPackageReplacedFromRegistry(): void
    {
        $registry = $this->registry();

        $this->publish($registry, 'widgets', ['1.0.0' => ['dependencies' => [self::LOCAL_NAME => '^2.0']]]);
        $this->publish($registry, self::LOCAL_NAME, ['2.1.0' => []]);
        $this->writeJson(
            'package.json',
            [
                'dependencies' => ['widgets' => '^1.0'],
                'optionalDependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH],
            ],
        );
        $this->writeLocal(['version' => '1.0.0']);
        $this->install($registry);

        $requested = $registry->requested;
        $compact = $this->compactLock();

        $this->install($registry);

        self::assertSame(
            $requested,
            $registry->requested,
            'The registry entry of the dropped local package must not make the lock stale.',
        );
        self::assertStringEqualsFile(
            "{$this->root}/foxy.lock",
            $compact,
            'A fresh lock must not be rewritten.',
        );
        self::assertStringEqualsFile(
            "{$this->root}/node_modules/" . self::LOCAL_NAME . '/index.js',
            self::LOCAL_NAME . '@2.1.0',
            'The registry version must be installed.',
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
            $fetched,
            $registry->fetched,
            'Kept tarballs must not be fetched again.',
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

    public function testInstallSkipsOptionalLocalPackageWithoutManifest(): void
    {
        $this->writeJson(
            'package.json',
            [
                'dependencies' => ['left-pad' => '*'],
                'optionalDependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH],
            ],
        );

        $io = $this->io();

        $io
            ->expects(self::once())
            ->method('writeError')
            ->with(
                '<warning>The optional dependency "' . self::LOCAL_NAME . '" (file:' . self::LOCAL_PATH . ') was skipped: '
                . Message::NATIVE_LOCAL_PACKAGE_MISSING->getMessage($this->localDirectory(), self::LOCAL_NAME)
                . '</warning>',
            );

        $this->installer($this->registry(), $io)->install(
            "{$this->root}/package.json",
            "{$this->root}/foxy.lock",
            "{$this->root}/node_modules",
            false,
        );

        self::assertSame(
            ['' => self::kinds(['left-pad' => '*'], [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH])],
            $this->lock()['requirements'],
            'The manifest entry must stay in the fingerprint.',
        );
        self::assertSame(
            ['left-pad'],
            array_keys($this->lock()['packages']),
            'The missing local package must not be locked.',
        );
    }

    public function testInstallSkipsOptionalLocalSourceMissingInsideInstallDirectory(): void
    {
        $this->writeJson(
            'package.json',
            ['dependencies' => ['left-pad' => '*'], 'optionalDependencies' => ['foo' => 'file:packages/foo']],
        );

        $this->installIntoWithOptionalLocalWarning('foo', 'file:packages/foo', "{$this->root}/packages");

        self::assertFileExists(
            "{$this->root}/packages/left-pad/index.js",
            'A missing source cannot overlap and the installation must proceed.',
        );
    }

    public function testInstallSkipsOptionalLocalSourceWithoutManifestOutsideInstallDirectory(): void
    {
        $this->writeJson(
            'package.json',
            ['dependencies' => ['left-pad' => '*'], 'optionalDependencies' => ['foo' => 'file:packages/foo']],
        );
        $this->writeFile('packages/foo/README.md', 'readme');

        $this->installIntoWithOptionalLocalWarning('foo', 'file:packages/foo', "{$this->root}/node_modules");

        self::assertFileExists(
            "{$this->root}/node_modules/left-pad/index.js",
            'The remaining requirements must be installed.',
        );
    }

    public function testInstallSkipsPrefetchWithoutRegistryPackages(): void
    {
        $registry = $this->registry();

        $this->writeJson('package.json', ['dependencies' => [self::LOCAL_NAME => 'file:' . self::LOCAL_PATH]]);
        $this->writeLocal(['version' => '1.0.0']);
        $this->install($registry);

        self::assertSame(
            [],
            $registry->prefetched,
            'Local packages alone must not trigger a prefetch.',
        );
        self::assertFileExists(
            "{$this->root}/node_modules/" . self::LOCAL_NAME . '/package.json',
            'The local package must be copied.',
        );
    }

    public function testInstallTreatsMissingManifestAsEmpty(): void
    {
        $this->install($this->registry());

        self::assertSame(
            ['_readme' => self::README, 'requirements' => ['' => self::kinds([])], 'packages' => []],
            $this->lock(),
            'The lock file must hold empty requirements.',
        );
        self::assertDirectoryExists(
            "{$this->root}/node_modules",
            'The modules directory must be created.',
        );
        self::assertSame(
            [self::NOTHING],
            $this->output,
            'Only the no-change line must be reported.',
        );
    }

    public function testInstallUpdateOverwritesMalformedLock(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*']]);
        $this->writeFile('foxy.lock', '{');

        $this->install($this->registry(), true);

        self::assertSame(
            ['' => self::kinds(['left-pad' => '*'])],
            $this->lock()['requirements'],
            'The malformed lock must be replaced.',
        );
    }

    public function testInstallUpdatesPackageWhoseRecordedVersionDiffers(): void
    {
        $registry = $this->registry();

        $this->writeJson('package.json', ['dependencies' => ['bootstrap' => '5.2.0']]);
        $this->install($registry);
        $this->writeJson('package.json', ['dependencies' => ['bootstrap' => '^5.3']]);
        $this->install($registry);

        self::assertSame(
            [
                '  - Installing @popperjs/core (2.11.8): Extracting archive',
                '  - Updating bootstrap (5.2.0 => 5.3.8): Extracting archive',
            ],
            $this->output,
            'A recorded version must turn the extraction into an update.',
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

    public function testThrowRuntimeExceptionWhenLocalPackageContainsInstallDirectory(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['foo' => 'file:packages/foo']]);
        $this->writeJson('packages/foo/package.json', ['version' => '1.0.0']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_INSTALL_DIR_OVERLAPS_LOCAL->getMessage(
                "{$this->root}/packages/foo/node_modules",
                'foo',
                (new Filesystem())->normalizePath("{$this->root}/packages/foo"),
            ),
        );

        $this->installInto("{$this->root}/packages/foo/node_modules");
    }

    public function testThrowRuntimeExceptionWhenLocalPackageLiesInInstallDirectory(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['foo' => 'file:packages/foo']]);
        $this->writeJson('packages/foo/package.json', ['version' => '1.0.0']);

        try {
            $this->installInto("{$this->root}/packages");

            self::fail('Expected the overlap to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::NATIVE_INSTALL_DIR_OVERLAPS_LOCAL->getMessage(
                    "{$this->root}/packages",
                    'foo',
                    (new Filesystem())->normalizePath("{$this->root}/packages/foo"),
                ),
                $exception->getMessage(),
                'The install directory, the package, and its source must be named.',
            );
        }

        self::assertFileExists(
            "{$this->root}/packages/foo/package.json",
            'Nothing may be removed before the check.',
        );
    }

    public function testThrowRuntimeExceptionWhenLocalPackageLiesInSymlinkedInstallDirectory(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['foo' => 'file:packages/foo']]);
        $this->writeJson('packages/foo/package.json', ['version' => '1.0.0']);

        if ('\\' === DIRECTORY_SEPARATOR || !@symlink("{$this->root}/packages", "{$this->root}/modules")) {
            self::markTestSkipped('PHP does not resolve symbolic links with realpath() on Windows.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_INSTALL_DIR_OVERLAPS_LOCAL->getMessage(
                "{$this->root}/modules",
                'foo',
                (new Filesystem())->normalizePath("{$this->root}/packages/foo"),
            ),
        );

        $this->installInto("{$this->root}/modules");
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

    public function testThrowRuntimeExceptionWhenOptionalEntryBecomesMandatory(): void
    {
        $registry = $this->registry();

        $this->writeJson(
            'package.json',
            ['dependencies' => ['left-pad' => '*'], 'optionalDependencies' => ['missing-pkg' => '^1.0']],
        );
        $this->install($registry);
        $this->writeJson('package.json', ['dependencies' => ['left-pad' => '*', 'missing-pkg' => '^1.0']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_PACKAGE_NOT_FOUND->getMessage('missing-pkg', 'memory'),
        );

        $this->install($registry);
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

    public function testThrowRuntimeExceptionWhenSkippedOptionalLocalSourceLiesInInstallDirectory(): void
    {
        $this->writeJson('package.json', ['optionalDependencies' => ['foo' => 'file:packages/foo']]);
        $this->writeFile('packages/foo/README.md', 'readme');

        try {
            $this->installInto("{$this->root}/packages");

            self::fail(
                'Expected the overlap to be rejected.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::NATIVE_INSTALL_DIR_OVERLAPS_LOCAL->getMessage(
                    "{$this->root}/packages",
                    'foo',
                    (new Filesystem())->normalizePath("{$this->root}/packages/foo"),
                ),
                $exception->getMessage(),
                'The skipped source must still be checked.',
            );
        }

        self::assertFileExists(
            "{$this->root}/packages/foo/README.md",
            'Nothing may be removed before the check.',
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

    private function install(
        InMemoryRegistry $registry,
        bool $update = false,
        int $verbosity = IOInterface::NORMAL,
    ): void {
        $this->installer($registry, $this->io($verbosity))->install(
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

    private function installInto(string $installDirectory): void
    {
        $this->installer($this->registry(), $this->io())->install(
            "{$this->root}/package.json",
            "{$this->root}/foxy.lock",
            $installDirectory,
            false,
        );
    }

    /**
     * Installs into the directory and expects one skipped-optional warning for a local package without a manifest.
     */
    private function installIntoWithOptionalLocalWarning(string $name, string $spec, string $installDirectory): void
    {
        $directory = (new Filesystem())->normalizePath("{$this->root}/" . substr($spec, strlen('file:')));

        $io = $this->io();

        $io
            ->expects(self::once())
            ->method('writeError')
            ->with(
                "<warning>The optional dependency \"{$name}\" ({$spec}) was skipped: "
                . Message::NATIVE_LOCAL_PACKAGE_MISSING->getMessage($directory, $name) . '</warning>',
            );

        $this->installer($this->registry(), $io)->install(
            "{$this->root}/package.json",
            "{$this->root}/foxy.lock",
            $installDirectory,
            false,
        );
    }

    /**
     * Installs with the default registry and expects one skipped-optional warning for a package missing from it.
     */
    private function installWithOptionalWarning(string $name, string $spec): void
    {
        $io = $this->io();

        $io
            ->expects(self::once())
            ->method('writeError')
            ->with(
                "<warning>The optional dependency \"{$name}\" ({$spec}) was skipped: "
                . Message::NATIVE_PACKAGE_NOT_FOUND->getMessage($name, 'memory') . '</warning>',
            );

        $this->installer($this->registry(), $io)->install(
            "{$this->root}/package.json",
            "{$this->root}/foxy.lock",
            "{$this->root}/node_modules",
            false,
        );
    }

    /**
     * Returns an output mock that records into {@see $output} the lines visible at the given verbosity, as Composer
     * filters them, and checks that every line ends with a newline.
     */
    private function io(int $verbosity = IOInterface::NORMAL): IOInterface&MockObject
    {
        $this->output = [];

        $io = $this->createMock(IOInterface::class);

        $io
            ->method('write')
            ->willReturnCallback(
                function (
                    string $message,
                    bool $newline = true,
                    int $level = IOInterface::NORMAL,
                ) use ($verbosity): void {
                    self::assertTrue($newline, 'Every line must end with a newline.');

                    if ($level <= $verbosity) {
                        $this->output[] = $message;
                    }
                },
            );

        return $io;
    }

    /**
     * Returns the fingerprint of one source as the lock file decodes it.
     *
     * @param array<string, string> $dependencies Mandatory specifications.
     * @param array<string, string> $optionalDependencies Optional specifications.
     *
     * @return array{dependencies: array<string, string>, optionalDependencies: array<string, string>}
     */
    private static function kinds(array $dependencies, array $optionalDependencies = []): array
    {
        return ['dependencies' => $dependencies, 'optionalDependencies' => $optionalDependencies];
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
     * Returns the fingerprint of one local source as the lock file decodes it, its version first.
     *
     * @param array<string, string> $dependencies Mandatory specifications.
     * @param array<string, string> $optionalDependencies Optional specifications.
     *
     * @return array{version: string, dependencies: array<string, string>, optionalDependencies: array<string, string>}
     */
    private static function localKinds(
        string $version,
        array $dependencies,
        array $optionalDependencies = [],
    ): array {
        return ['version' => $version] + self::kinds($dependencies, $optionalDependencies);
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

    /**
     * Removes a file, a directory, or a link below the test root.
     */
    private function removePath(string $path): void
    {
        (new Filesystem())->remove("{$this->root}/{$path}");
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return json_decode(
            (string) file_get_contents("{$this->root}/node_modules/.foxy-installed.json"),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
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
