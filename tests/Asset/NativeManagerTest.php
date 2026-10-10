<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\{Filesystem, Platform};
use Foxy\Asset\NativeManager;
use Foxy\Audit\Parser\NativeAuditParser;
use Foxy\Config\Config;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Fallback\FallbackInterface;
use Foxy\Native\NativeInstallerInterface;
use Foxy\Tests\Fixtures\Native\InMemoryRegistry;
use Foxy\Tests\Provider\NativeManagerProvider;
use PHPUnit\Framework\Attributes\{DataProviderExternal, PreserveGlobalState, RunInSeparateProcess};
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Xepozz\InternalMocker\MockerState;

use function basename;
use function chdir;
use function define;
use function defined;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function json_encode;
use function mkdir;
use function str_replace;
use function strtr;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

/**
 * Unit tests for {@see NativeManager} selection, validation, install directory resolution, installer orchestration, and
 * the registry audit.
 *
 * {@see NativeManagerProvider} for test case data providers.
 */
final class NativeManagerTest extends TestCase
{
    private const string INSTALLING = '<info>Installing frontend dependencies with the native manager</info>';
    private const string INTEGRITY = 'sha512-aGVsbG8=';
    private const string UPDATING = '<info>Updating frontend dependencies with the native manager</info>';

    private string $cwd = '';
    private FallbackInterface&MockObject $fallback;
    private NativeInstallerInterface&MockObject $installer;
    private IOInterface&MockObject $io;

    /**
     * @var list<string> Symbolic links created by the test, removed before the temporary directory.
     */
    private array $links = [];
    private string $oldCwd = '';
    private InMemoryRegistry $registry;

    public function testAddDependenciesWritesManifest(): void
    {
        $rootPackage = $this->createMock(RootPackageInterface::class);

        $rootPackage
            ->method('getLicense')
            ->willReturn([]);

        $this->manager(['run-asset-manager' => false])->addDependencies(
            $rootPackage,
            ['@composer-asset/foo--bar' => 'path/foo/bar/package.json'],
        );

        self::assertStringContainsString(
            '"@composer-asset/foo--bar": "file:./path/foo/bar"',
            (string) file_get_contents("{$this->cwd}/package.json"),
            'The merged dependency must be written.',
        );
    }

    public function testAuditRequestsRegistryPackagesSortedByNameWithoutLocalPackages(): void
    {
        $this->writeLock(
            [
                'zeta' => self::registryEntry('zeta', '2.0.0'),
                '@composer-asset/foo--bar' => ['version' => '1.0.0', 'file' => './path/foo/bar'],
                'alpha' => self::registryEntry('alpha', '1.2.3'),
                '9' => self::registryEntry('9', '0.9.0'),
                '10' => self::registryEntry('10', '1.0.0'),
            ],
        );

        $result = $this->manager()->audit(false);

        self::assertSame(
            [
                [
                    '10' => ['1.0.0'],
                    '9' => ['0.9.0'],
                    'alpha' => ['1.2.3'],
                    'zeta' => ['2.0.0'],
                ],
            ],
            $this->registry->advisoryRequests,
            'One request must carry the registry versions sorted as strings.',
        );
        self::assertSame(
            0,
            $result->exitCode,
            'An empty document must exit with `0`.',
        );
        self::assertSame(
            '{}',
            $result->output,
            'The document must be returned as received.',
        );
    }

    public function testAuditReturnsDocumentWithFindingsAndExitOne(): void
    {
        $document = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/Audit/bun-populated.json');

        $this->registry->addAdvisories($document);

        $this->writeLock(['lodash' => self::registryEntry('lodash', '4.17.15')]);

        $result = $this->manager()->audit(false);

        self::assertSame(
            1,
            $result->exitCode,
            'A document with advisories must exit with `1`.',
        );
        self::assertSame(
            $document,
            $result->output,
            'The document must be returned verbatim.',
        );
        self::assertSame(
            '',
            $result->errorOutput,
            'The error output must be empty.',
        );
    }

    public function testAuditSkipsRequestWhenLockHoldsOnlyLocalPackages(): void
    {
        $this->writeLock(['@composer-asset/foo--bar' => ['version' => '1.0.0', 'file' => './path/foo/bar']]);

        $result = $this->manager()->audit(false);

        self::assertSame(
            [],
            $this->registry->advisoryRequests,
            'No request must be sent without registry packages.',
        );
        self::assertSame(
            0,
            $result->exitCode,
            'A lock without registry packages must exit with `0`.',
        );
        self::assertSame(
            '{}',
            $result->output,
            'The document must be the empty object.',
        );
        self::assertSame(
            '',
            $result->errorOutput,
            'The error output must be empty.',
        );
    }

    public function testGetAuditParserReturnsNativeParser(): void
    {
        self::assertInstanceOf(
            NativeAuditParser::class,
            $this->manager()->getAuditParser(),
            'The native parser must read the bulk advisories document.',
        );
    }

    public function testIsAvailableRequiresLockFileAndResolvesManager(): void
    {
        $config = new Config(['manager-timeout' => ['native' => 5]]);
        $manager = new NativeManager($this->io, $config, new Filesystem(), $this->installer, $this->registry);

        self::assertNull(
            $config->get('manager-timeout'),
            'No manager-keyed value applies before resolution.',
        );
        self::assertFalse(
            $manager->isAvailable(),
            'A missing lock file must not make the manager available.',
        );
        self::assertSame(
            5,
            $config->get('manager-timeout'),
            'The manager must be recorded as resolved.',
        );

        file_put_contents("{$this->cwd}/foxy.lock", '{}');

        self::assertTrue(
            $manager->isAvailable(),
            'An existing lock file must make the manager available.',
        );
    }

    public function testIsInstalledFollowsConfiguredDirectory(): void
    {
        $manager = $this->manager(['native-install-dir' => 'vendor/npm-asset']);

        $this->markInstalled();

        self::assertFalse(
            $manager->isInstalled(),
            'An existing `node_modules` must not count.',
        );

        mkdir("{$this->cwd}/vendor/npm-asset", 0o777, true);

        self::assertTrue(
            $manager->isInstalled(),
            'The configured directory and the manifest must count.',
        );

        unlink("{$this->cwd}/package.json");

        self::assertFalse(
            $manager->isInstalled(),
            'The manifest must exist too.',
        );
    }

    public function testNameAccessorsReturnNativeValues(): void
    {
        $manager = $this->manager();

        self::assertSame(
            'foxy.lock',
            $manager->getLockPackageName(),
            'The lock file must be `foxy.lock`.',
        );
        self::assertSame(
            'native',
            $manager->getName(),
            'The manager name must be `native`.',
        );
        self::assertSame(
            'package.json',
            $manager->getPackageName(),
            'The manifest must be `package.json`.',
        );
        self::assertSame(
            '*',
            $manager->getVersionConstraint(),
            'Any version must be accepted.',
        );
    }

    public function testRunAcceptsInstallDirectoryDifferingFromRootPackageDirOnlyByCase(): void
    {
        if (Platform::isWindows()) {
            self::markTestSkipped('Paths differing only by letter case name the same directory on Windows.');
        }

        mkdir("{$this->cwd}/app");

        $root = $this->cwd . DIRECTORY_SEPARATOR . 'app';

        $this->installer
            ->expects(self::once())
            ->method('install')
            ->with(
                $root . DIRECTORY_SEPARATOR . 'package.json',
                $root . DIRECTORY_SEPARATOR . 'foxy.lock',
                (new Filesystem())->normalizePath("{$this->cwd}/APP"),
                true,
            );

        self::assertSame(
            0,
            $this->manager(
                [
                    'run-asset-manager' => true,
                    'native-install-dir' => "{$this->cwd}/APP",
                    'root-package-json-dir' => 'app',
                ],
            )->run(),
            'Case must be significant outside Windows.',
        );
    }

    public function testRunForcesInstallWhenUpdatesAreDisabled(): void
    {
        $this->markInstalled();

        $this->expectInstall($this->cwd, false, self::INSTALLING);

        $manager = $this->manager(['run-asset-manager' => true]);

        $manager->setUpdatable(false);

        self::assertSame(
            0,
            $manager->run(),
            'A successful run must exit with `0`.',
        );
    }

    #[DataProviderExternal(NativeManagerProvider::class, 'installDirectories')]
    public function testRunInstallsIntoConfiguredDirectory(
        int|string|null $value,
        string|null $rootPackageDir,
        string $expected,
    ): void {
        $root = $this->cwd;

        if (null !== $rootPackageDir) {
            $root .= DIRECTORY_SEPARATOR . $rootPackageDir;

            mkdir($root);
        }

        $this->installer
            ->expects(self::once())
            ->method('install')
            ->with(
                $root . DIRECTORY_SEPARATOR . 'package.json',
                $root . DIRECTORY_SEPARATOR . 'foxy.lock',
                (new Filesystem())->normalizePath(str_replace('{cwd}', $this->cwd, $expected)),
                true,
            );

        $this->manager(
            ['run-asset-manager' => true, 'native-install-dir' => $value, 'root-package-json-dir' => $rootPackageDir],
        )->run();
    }

    public function testRunInstallsIntoConfiguredRootPackageDir(): void
    {
        mkdir("{$this->cwd}/web");

        $this->expectInstall($this->cwd . DIRECTORY_SEPARATOR . 'web', true, self::UPDATING);

        self::assertSame(
            0,
            $this->manager(['run-asset-manager' => true, 'root-package-json-dir' => 'web'])->run(),
            'A successful run must exit with `0`.',
        );
    }

    public function testRunInstallsThroughSymlinkedInstallDirectory(): void
    {
        mkdir("{$this->cwd}/shared");

        $this->link("{$this->cwd}/shared", "{$this->cwd}/node_modules");
        $this->expectInstall($this->cwd, true, self::UPDATING);

        self::assertSame(
            0,
            $this->manager(['run-asset-manager' => true])->run(),
            'The link path, not its target, must reach the installer.',
        );
    }

    public function testRunRestoresFallbackAndRethrowsInstallerFailure(): void
    {
        $failure = new RuntimeException('Install failed.');

        $this->installer
            ->expects(self::once())
            ->method('install')
            ->willThrowException($failure);
        $this->fallback
            ->expects(self::once())
            ->method('restore');

        try {
            $this->manager(['run-asset-manager' => true])->run();

            self::fail('Expected the installation to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                $failure,
                $exception,
                'The installer exception must be rethrown unchanged.',
            );
        }
    }

    public function testRunReturnsZeroWithoutInstallingWhenDisabled(): void
    {
        $this->installer
            ->expects(self::never())
            ->method('install');
        $this->io
            ->expects(self::never())
            ->method('write');

        self::assertSame(
            0,
            $this->manager(['run-asset-manager' => false])->run(),
            'A disabled run must exit with `0`.',
        );
    }

    public function testRunUpdatesWhenInstalled(): void
    {
        $this->markInstalled();

        $this->expectInstall($this->cwd, true, self::UPDATING);

        self::assertSame(
            0,
            $this->manager(['run-asset-manager' => true])->run(),
            'A successful run must exit with `0`.',
        );
    }

    public function testRunUpdatesWithoutInstallDirectoryWhenUpdatable(): void
    {
        $this->expectInstall($this->cwd, true, self::UPDATING);

        self::assertSame(
            0,
            $this->manager(['run-asset-manager' => true])->run(),
            'A successful run must exit with `0`.',
        );
    }

    #[DataProviderExternal(NativeManagerProvider::class, 'protectedLinkTargets')]
    public function testThrowRuntimeExceptionForInstallDirectoryResolvingToProtectedDirectory(
        string $link,
        string $target,
        string $value,
    ): void {
        self::skipUnresolvedLinks();

        $placeholders = ['{basename}' => basename($this->cwd), '{cwd}' => $this->cwd, '{parent}' => dirname($this->cwd)];

        $this->link(strtr($target, $placeholders), "{$this->cwd}/{$link}");

        $this->installer
            ->expects(self::never())
            ->method('install');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_INSTALL_DIR_INVALID->getMessage(
                (new Filesystem())->normalizePath($this->cwd . DIRECTORY_SEPARATOR . strtr($value, $placeholders)),
            ),
        );

        $this->manager(['run-asset-manager' => true, 'native-install-dir' => strtr($value, $placeholders)])->run();
    }

    #[DataProviderExternal(NativeManagerProvider::class, 'invalidInstallDirectories')]
    public function testThrowRuntimeExceptionForInvalidInstallDirectory(string $value, string $expected): void
    {
        $placeholders = ['{cwd}' => $this->cwd, '{parent}' => dirname($this->cwd)];

        $this->installer
            ->expects(self::never())
            ->method('install');
        $this->io
            ->expects(self::never())
            ->method('write');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_INSTALL_DIR_INVALID->getMessage(
                (new Filesystem())->normalizePath(strtr($expected, $placeholders)),
            ),
        );

        $this->manager(['run-asset-manager' => true, 'native-install-dir' => strtr($value, $placeholders)])->run();
    }

    public function testThrowRuntimeExceptionForMalformedAdvisoriesDocument(): void
    {
        $this->registry->addAdvisories('[]');

        $this->writeLock(['lodash' => self::registryEntry('lodash', '4.17.15')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_OUTPUT_MALFORMED->getMessage(
                'native',
                Message::AUDIT_REPORT_JSON_OBJECT_EXPECTED->getMessage(),
            ),
        );

        $this->manager()->audit(false);
    }

    public function testThrowRuntimeExceptionWhenAuditLockFileIsInvalid(): void
    {
        file_put_contents("{$this->cwd}/foxy.lock", '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_LOCK_INVALID->getMessage($this->cwd . DIRECTORY_SEPARATOR . 'foxy.lock'),
        );

        $this->manager()->audit(false);
    }

    public function testThrowRuntimeExceptionWhenAuditLockFileIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_MANAGER_LOCK_FILE_MISSING->getMessage(
                'native',
                $this->cwd . DIRECTORY_SEPARATOR . 'foxy.lock',
            ),
        );

        $this->manager()->audit(false);
    }

    public function testThrowRuntimeExceptionWhenFallbackRestoreFails(): void
    {
        $failure = new RuntimeException('Install failed.');

        $this->installer
            ->method('install')
            ->willThrowException($failure);
        $this->fallback
            ->expects(self::once())
            ->method('restore')
            ->willThrowException(new RuntimeException('Fallback failed.'));

        try {
            $this->manager(['run-asset-manager' => true])->run();

            self::fail('Expected the fallback restoration to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_MANAGER_FALLBACK_RESTORE_FAILED->getMessage('Fallback failed.'),
                $exception->getMessage(),
                'The fallback failure must be reported.',
            );
            self::assertSame(
                $failure,
                $exception->getPrevious(),
                'The installer exception must be preserved.',
            );
        }
    }

    public function testThrowRuntimeExceptionWhenInstallDirectoryIsDanglingSymlink(): void
    {
        $this->link("{$this->cwd}/missing", "{$this->cwd}/node_modules");

        $this->installer
            ->expects(self::never())
            ->method('install');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::SOLVER_PATH_RESOLVE_FAILED->getMessage(
                (new Filesystem())->normalizePath("{$this->cwd}/node_modules"),
            ),
        );

        $this->manager(['run-asset-manager' => true])->run();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThrowRuntimeExceptionWhenInstallDirectoryIsRootPackageDirInOtherCaseOnWindows(): void
    {
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            define('PHP_WINDOWS_VERSION_BUILD', 1);
        }

        mkdir("{$this->cwd}/app");

        $this->installer
            ->expects(self::never())
            ->method('install');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_INSTALL_DIR_INVALID->getMessage((new Filesystem())->normalizePath("{$this->cwd}/APP")),
        );

        $this->manager(
            ['run-asset-manager' => true, 'native-install-dir' => "{$this->cwd}/APP", 'root-package-json-dir' => 'app'],
        )->run();
    }

    public function testThrowRuntimeExceptionWhenInstallDirectoryIsSymlinkedRootPackageDir(): void
    {
        self::skipUnresolvedLinks();

        mkdir("{$this->cwd}/app");

        $this->link("{$this->cwd}/app", "{$this->cwd}/web");

        $this->installer
            ->expects(self::never())
            ->method('install');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_INSTALL_DIR_INVALID->getMessage((new Filesystem())->normalizePath("{$this->cwd}/app")),
        );

        $this->manager(
            ['run-asset-manager' => true, 'native-install-dir' => "{$this->cwd}/app", 'root-package-json-dir' => 'web'],
        )->run();
    }

    public function testThrowRuntimeExceptionWhenProductionOnlyAuditIsRequested(): void
    {
        $this->writeLock(['lodash' => self::registryEntry('lodash', '4.17.15')]);

        try {
            $this->manager()->audit(true);

            self::fail(
                'Expected the production-only native audit to be rejected.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_NATIVE_AUDIT_NO_DEV_UNSUPPORTED->getMessage(),
                $exception->getMessage(),
                'The rejection must explain the dependency scope.',
            );
        }

        self::assertSame(
            [],
            $this->registry->advisoryRequests,
            'The registry must not be asked.',
        );
    }

    public function testThrowRuntimeExceptionWhenRootPackageDirIsMissing(): void
    {
        $this->installer
            ->expects(self::never())
            ->method('install');
        $this->io
            ->expects(self::never())
            ->method('write');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_ROOT_PACKAGE_DIR_MISSING->getMessage($this->cwd . DIRECTORY_SEPARATOR . 'missing'),
        );

        $this->manager(['run-asset-manager' => true, 'root-package-json-dir' => 'missing'])->run();
    }

    public function testThrowRuntimeExceptionWhenZlibIsMissing(): void
    {
        MockerState::addCondition('Foxy\\Asset', 'extension_loaded', ['zlib'], false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_EXTENSION_MISSING->getMessage('zlib'),
        );

        $this->manager()->validate();
    }

    public function testThrowRuntimeExceptionWhenZlibIsMissingDuringRun(): void
    {
        MockerState::addCondition('Foxy\\Asset', 'extension_loaded', ['zlib'], false);

        $this->installer
            ->expects(self::never())
            ->method('install');
        $this->io
            ->expects(self::never())
            ->method('write');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::NATIVE_EXTENSION_MISSING->getMessage('zlib'),
        );

        $this->manager(['run-asset-manager' => true])->run();
    }

    public function testValidateAcceptsLoadedZlib(): void
    {
        $this->manager()->validate();

        $this->expectNotToPerformAssertions();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fallback = $this->createMock(FallbackInterface::class);
        $this->installer = $this->createMock(NativeInstallerInterface::class);
        $this->io = $this->createMock(IOInterface::class);
        $this->registry = new InMemoryRegistry();
        $this->oldCwd = (string) getcwd();

        $directory = sys_get_temp_dir() . '/' . uniqid('foxy_native_manager_test_', true);

        mkdir($directory, 0o777, true);
        chdir($directory);

        $this->cwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);

        $fs = new Filesystem();

        foreach ($this->links as $link) {
            $fs->unlink($link);
        }

        $fs->removeDirectory($this->cwd);
    }

    /**
     * Expects one installation into the `node_modules` directory of the root package and the given progress line.
     *
     * @param string $root Root package directory, without a trailing separator.
     */
    private function expectInstall(string $root, bool $update, string $line): void
    {
        $this->installer
            ->expects(self::once())
            ->method('install')
            ->with(
                $root . DIRECTORY_SEPARATOR . 'package.json',
                $root . DIRECTORY_SEPARATOR . 'foxy.lock',
                (new Filesystem())->normalizePath($root . DIRECTORY_SEPARATOR . 'node_modules'),
                $update,
            );
        $this->io
            ->expects(self::once())
            ->method('write')
            ->with($line);
    }

    /**
     * Creates a symbolic link, or skips the test when the platform refuses it.
     */
    private function link(string $target, string $link): void
    {
        if (!@symlink($target, $link)) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        $this->links[] = $link;
    }

    /**
     * @param array<string, mixed> $config Foxy configuration.
     */
    private function manager(array $config = []): NativeManager
    {
        return new NativeManager(
            $this->io,
            new Config($config),
            new Filesystem(),
            $this->installer,
            $this->registry,
            $this->fallback,
        );
    }

    private function markInstalled(): void
    {
        file_put_contents("{$this->cwd}/package.json", '{}');
        mkdir("{$this->cwd}/node_modules");
    }

    /**
     * Returns a registry lock entry for the version.
     *
     * @return array<string, string>
     */
    private static function registryEntry(string $name, string $version): array
    {
        return [
            'version' => $version,
            'resolved' => "https://registry.npmjs.org/{$name}/-/{$name}-{$version}.tgz",
            'integrity' => self::INTEGRITY,
        ];
    }

    /**
     * Skips the test on Windows, where `realpath()` does not resolve symbolic links.
     */
    private static function skipUnresolvedLinks(): void
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            self::markTestSkipped('PHP does not resolve symbolic links with realpath() on Windows.');
        }
    }

    /**
     * Writes `foxy.lock` with the given package entries and no requirements.
     *
     * @param array<array-key, array<string, string>> $packages Lock entries keyed by package name, in file order.
     */
    private function writeLock(array $packages): void
    {
        file_put_contents(
            "{$this->cwd}/foxy.lock",
            json_encode(['requirements' => new stdClass(), 'packages' => (object) $packages], JSON_THROW_ON_ERROR),
        );
    }
}
