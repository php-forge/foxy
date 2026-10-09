<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\{Filesystem, ProcessExecutor};
use Foxy\Config\Config;
use Foxy\Converter\VersionConverterInterface;
use Foxy\Fallback\FallbackInterface;
use Foxy\Tests\Fixtures\Asset\InspectableAssetManager;
use Foxy\Tests\Fixtures\Util\ProcessExecutorMock;
use Foxy\Tests\Provider\AbstractAssetManagerProvider;
use PHPUnit\Framework\Attributes\{DataProviderExternal, PreserveGlobalState, RunInSeparateProcess};
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Seld\JsonLint\ParsingException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Xepozz\InternalMocker\MockerState;

use function chdir;
use function define;
use function defined;
use function file_put_contents;
use function getcwd;
use function getenv;
use function putenv;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * Unit tests for {@see \Foxy\Asset\AbstractAssetManager} through the {@see InspectableAssetManager} fixture.
 *
 * {@see AbstractAssetManagerProvider} for test case data providers.
 */
final class AbstractAssetManagerTest extends TestCase
{
    private Config|null $config = null;
    private string|null $cwd = null;
    private ProcessExecutorMock|null $executor = null;
    private FallbackInterface|MockObject|null $fallback = null;
    private Filesystem|MockObject|null $fs = null;
    private IOInterface|MockObject|null $io = null;
    private string|null $oldCwd = null;
    private RootPackageInterface|MockObject|null $rootPackage = null;
    private SymfonyFilesystem|null $sfs = null;

    public function testActionHookIsSkippedWhenManagerExecutionIsDisabled(): void
    {
        $this->config = new Config([], ['run-asset-manager' => false]);

        $manager = $this->createManager();

        $manager->addDependencies(
            $this->rootPackage,
            ['@composer-asset/foo--bar' => 'path/foo/bar/package.json'],
        );

        self::assertNull(
            $manager->getHandledDependencies(),
            'Disabled execution must leave handled dependencies unset.',
        );
    }

    public function testActionHookRemainsExtensible(): void
    {
        $this->config = new Config([], ['run-asset-manager' => true]);

        $this->io
            ->expects(self::once())
            ->method('write')
            ->with('<info>Merging Composer dependencies in the asset package</info>');

        $manager = $this->createManager();

        $manager->addDependencies(
            $this->rootPackage,
            ['@composer-asset/foo--bar' => 'path/foo/bar/package.json'],
        );

        self::assertSame(
            [],
            $manager->getHandledDependencies(),
            'The default hook must not handle dependencies.',
        );
    }

    public function testAddDependenciesPropagatesFailureWithoutFallback(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', 'invalid json');

        $manager = new InspectableAssetManager(
            $this->io,
            $this->config,
            $this->executor,
            $this->fs,
        );

        $this->expectException(ParsingException::class);

        $manager->addDependencies($this->rootPackage, []);
    }

    public function testAddDependenciesRestoresFallbackAfterFailure(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', 'invalid json');

        $this->fallback
            ->expects(self::once())
            ->method('restore');

        $this->expectException(ParsingException::class);

        $this->createManager()->addDependencies($this->rootPackage, []);
    }

    public function testAuditCapturesStandardAndErrorOutputWithoutStreaming(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'inspectable.lock', '{}');

        $position = 0;

        $executor = $this->createMock(ProcessExecutor::class);

        $executor
            ->expects(self::exactly(2))
            ->method('execute')
            ->willReturnCallback(
                static function (mixed $command, mixed &$output = null, mixed $cwd = null) use (&$position): int {
                    self::assertNull($cwd, 'Audit commands must not set a working directory.');

                    if (0 === $position++) {
                        self::assertSame(
                            'inspectable --version',
                            $command,
                            'Version lookup must use the configured binary.',
                        );
                        $output = '42.0.0';

                        return 0;
                    }

                    self::assertSame(
                        'inspectable audit --prod',
                        $command,
                        'Audit command must include the production flag.',
                    );
                    $output = 'standard output';

                    return 1;
                },
            );
        $executor
            ->expects(self::once())
            ->method('getErrorOutput')
            ->willReturn('error output');

        $this->io
            ->expects(self::never())
            ->method('writeRaw');
        $this->io
            ->expects(self::never())
            ->method('writeErrorRaw');

        $manager = new InspectableAssetManager($this->io, $this->config, $executor, $this->fs, $this->fallback);

        $result = $manager->audit(true);

        self::assertSame(
            1,
            $result->exitCode,
            'The audit exit code must be preserved.',
        );
        self::assertSame(
            'standard output',
            $result->output,
            'Standard output must be captured.',
        );
        self::assertSame(
            'error output',
            $result->errorOutput,
            'Error output must be captured.',
        );
    }

    public function testAuditInvokesManagerConfigurationValidation(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'inspectable.lock', '{}');

        $this->executor->addExpectedValues(0, '42.0.0');
        $this->executor->addExpectedValues(0, '{}');

        $manager = $this->createManager();

        $manager->audit(true);

        self::assertTrue(
            $manager->getAuditValidationForTest(),
            'Manager configuration must be validated before auditing.',
        );
    }

    public function testAuditOverridesAndRestoresManagerEnvironment(): void
    {
        $name = 'FOXY_ABSTRACT_ASSET_MANAGER_AUDIT';

        $processValue = getenv($name);

        $environment = $_ENV;
        $server = $_SERVER;

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'inspectable.lock', '{}');
        putenv("{$name}=process-before-audit");

        $_ENV[$name] = 'env-before-audit';
        $_SERVER[$name] = 'server-before-audit';

        $position = 0;

        $executor = $this->createMock(ProcessExecutor::class);

        $executor
            ->expects(self::exactly(2))
            ->method('execute')
            ->willReturnCallback(
                static function (mixed $command, mixed &$output = null) use (&$position, $name): int {
                    if (0 === $position++) {
                        $output = '42.0.0';

                        return 0;
                    }

                    self::assertSame(
                        'audit-value',
                        getenv($name),
                        'Process environment must include the audit override.',
                    );
                    self::assertSame('audit-value', $_ENV[$name], '$_ENV must include the audit override.');
                    self::assertSame('audit-value', $_SERVER[$name], '$_SERVER must include the audit override.');
                    $output = '{}';

                    return 0;
                },
            );

        try {
            $manager = new InspectableAssetManager(
                $this->io,
                $this->config,
                $executor,
                $this->fs,
                $this->fallback,
            );

            $manager->setAuditEnvironmentForTest([$name => 'audit-value']);
            $manager->audit(false);

            self::assertSame(
                'process-before-audit',
                getenv($name),
                'Process environment must be restored.',
            );
            self::assertSame(
                'env-before-audit',
                $_ENV[$name],
                '$_ENV must be restored.',
            );
            self::assertSame(
                'server-before-audit',
                $_SERVER[$name],
                '$_SERVER must be restored.',
            );
        } finally {
            putenv(false === $processValue ? $name : "{$name}={$processValue}");

            $_ENV = $environment;
            $_SERVER = $server;
        }
    }

    public function testAuditRestoresTimeoutWhenExecutorThrows(): void
    {
        $originalTimeout = ProcessExecutor::getTimeout();

        $expectedTimeout = 42;
        $managerTimeout = 900;
        $observedTimeout = null;

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'inspectable.lock', '{}');

        $this->config = new Config(
            [
                'manager-timeout' => $managerTimeout,
                'run-asset-manager' => false,
            ],
        );

        $position = 0;

        $executor = $this->createMock(ProcessExecutor::class);

        $executor
            ->expects(self::exactly(2))
            ->method('execute')
            ->willReturnCallback(
                static function (mixed $command, mixed &$output = null) use (
                    &$observedTimeout,
                    &$position,
                ): int {
                    if (0 === $position++) {
                        $output = '42.0.0';

                        return 0;
                    }

                    $observedTimeout = ProcessExecutor::getTimeout();

                    throw new \RuntimeException('Audit execution failed.');
                },
            );
        $this->fallback->expects(self::never())->method('restore');

        try {
            ProcessExecutor::setTimeout($expectedTimeout);

            $manager = new InspectableAssetManager(
                $this->io,
                $this->config,
                $executor,
                $this->fs,
                $this->fallback,
            );

            try {
                $manager->audit(false);

                self::fail(
                    'Expected the audit process to fail.',
                );
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Audit execution failed.',
                    $exception->getMessage(),
                    'The original execution error must be propagated.',
                );
            }

            self::assertSame(
                $managerTimeout,
                $observedTimeout,
                'The configured timeout must apply during execution.',
            );
            self::assertSame(
                $expectedTimeout,
                ProcessExecutor::getTimeout(),
                'The previous timeout must be restored.',
            );
        } finally {
            ProcessExecutor::setTimeout($originalTimeout);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBuildCommandNormalizesWindowsBinaryPath(): void
    {
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            define('PHP_WINDOWS_VERSION_BUILD', 1);
        }

        $this->config = new Config(['manager-bin' => 'C:/tools/inspectable.exe']);

        self::assertSame(
            'C:\\tools\\inspectable.exe install',
            $this->createManager()->buildCommandForTest('inspectable.exe', 'install', 'install'),
            'Windows path separators must be normalized.',
        );
    }

    public function testBuildCommandUsesTrimmedGlobalAndActionOptions(): void
    {
        $this->config = new Config(
            [
                'manager-bin' => 'custom/bin',
                'manager-options' => '  --global  ',
                'manager-install-options' => '  --local  ',
            ],
        );

        self::assertSame(
            str_replace('/', DIRECTORY_SEPARATOR, 'custom/bin') . ' install --global --local',
            $this->createManager()->buildCommandForTest('inspectable', 'install', 'install'),
            'Options must be trimmed and ordered.',
        );
    }

    public function testInjectedVersionConverterReceivesTrimmedVersion(): void
    {
        $converter = $this->createMock(VersionConverterInterface::class);

        $converter
            ->expects(self::once())
            ->method('convertVersion')
            ->with('custom-version')
            ->willReturn('42.0.0');

        $this->executor->addExpectedValues(0, "  custom-version\n");

        self::assertTrue(
            $this->createManager($converter)->isAvailable(),
            'A successfully converted version must be available.',
        );
    }

    public function testIsAvailableRejectsEmptyNormalizedVersionOutput(): void
    {
        $converter = $this->createMock(VersionConverterInterface::class);

        $converter
            ->expects(self::never())
            ->method('convertVersion');

        $this->executor->addExpectedValues(0, 'unrecognized output');

        $manager = $this->createManager($converter);

        $manager->setNormalizedVersionOutputForTest('');

        self::assertFalse(
            $manager->isAvailable(),
            'Empty normalized output must be unavailable.',
        );
        self::assertSame(
            'unrecognized output',
            $manager->getVersionOutputForTest(),
            'Unrecognized version output must be preserved.',
        );
    }

    public function testIsAvailableRejectsWhitespaceOnlyVersion(): void
    {
        $converter = $this->createMock(VersionConverterInterface::class);

        $converter
            ->expects(self::never())
            ->method('convertVersion');

        $this->executor->addExpectedValues(0, " \n\t");

        self::assertFalse(
            $this->createManager($converter)->isAvailable(),
            'Whitespace-only version output must be unavailable.',
        );
    }

    public function testIsAvailableResolvesManagerSpecificConfiguration(): void
    {
        $this->config = new Config([], ['manager-version' => ['inspectable' => '>=42.0.0']]);

        $this->executor->addExpectedValues(0, '42.0.0');

        self::assertNull(
            $this->config->get('manager-version'),
            'Manager-specific configuration must not alter the global version.',
        );
        self::assertTrue(
            $this->createManager()->isAvailable(),
            'The manager must accept its configured version constraint.',
        );
        self::assertSame(
            '>=42.0.0',
            $this->config->get('manager-version'),
            'The manager-specific version must be resolved.',
        );
    }

    public function testMergeHookReceivesMergedPackageAndPreviousDependencies(): void
    {
        $this->config = new Config([], ['run-asset-manager' => true]);

        file_put_contents(
            $this->cwd . DIRECTORY_SEPARATOR . 'package.json',
            '{"dependencies":{"@composer-asset/foo--bar":"file:./path/foo/bar",'
            . '"@composer-asset/old--dependency":"file:./path/old/dependency","lodash":"^4.17.21"}}',
        );

        $manager = $this->createManager();

        $manager->addDependencies(
            $this->rootPackage,
            [
                '@composer-asset/foo--bar' => 'path/foo/bar/package.json',
                '@composer-asset/new--dependency' => 'path/new/dependency/package.json',
            ],
        );

        self::assertSame(
            [
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@composer-asset/old--dependency' => 'file:./path/old/dependency',
            ],
            $manager->getPreviousDependenciesForTest(),
            'Previous asset dependencies must be retained.',
        );
        self::assertSame(
            [
                'dependencies' => [
                    '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                    '@composer-asset/new--dependency' => 'file:./path/new/dependency',
                    'lodash' => '^4.17.21',
                ],
            ],
            $manager->getMergedPackageForTest(),
            'The merged package must retain asset and unrelated dependencies.',
        );
    }

    public function testMergeHookRunsWhenManagerExecutionIsDisabled(): void
    {
        $this->config = new Config([], ['run-asset-manager' => false]);

        $manager = $this->createManager();

        $manager->addDependencies(
            $this->rootPackage,
            ['@composer-asset/foo--bar' => 'path/foo/bar/package.json'],
        );

        self::assertNull(
            $manager->getHandledDependencies(),
            'Disabled execution must leave handled dependencies unset.',
        );
        self::assertSame(
            [],
            $manager->getPreviousDependenciesForTest(),
            'No previous dependencies should be recorded.',
        );
        self::assertSame(
            ['dependencies' => ['@composer-asset/foo--bar' => 'file:./path/foo/bar']],
            $manager->getMergedPackageForTest(),
            'The merged package must include the supplied asset dependency.',
        );
    }

    public function testRootPackageDirectoryFallsBackToCurrentDirectoryWhenConfiguredValueIsEmpty(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => '']);

        self::assertSame(
            $this->cwd,
            $this->createManager()->getRootPackageDirForTest(),
            'Empty configuration must resolve to the current directory.',
        );
    }

    #[DataProviderExternal(AbstractAssetManagerProvider::class, 'relativeRootPackageDirectories')]
    public function testRootPackageDirectoryOnlyRecognizesAnchoredAbsolutePaths(
        string $configuredDirectory,
        string $relativeDirectory,
    ): void {
        $this->config = new Config([], ['root-package-json-dir' => $configuredDirectory]);

        self::assertSame(
            $this->cwd . DIRECTORY_SEPARATOR . $relativeDirectory,
            $this->createManager()->getRootPackageDirForTest(),
            'Relative paths must be anchored to the current directory.',
        );
    }

    public function testRootPackageDirectoryRecognizesLeadingBackslashAsAbsolute(): void
    {
        $rootPackageDir = '\\server\\share';

        $this->config = new Config([], ['root-package-json-dir' => $rootPackageDir]);

        self::assertSame(
            $rootPackageDir,
            $this->createManager()->getRootPackageDirForTest(),
            'A leading backslash must remain an absolute path.',
        );
    }

    public function testRootPackageDirectoryRemovesTrailingSeparators(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => $this->cwd . '///']);

        self::assertSame(
            $this->cwd,
            $this->createManager()->getRootPackageDirForTest(),
            'Trailing separators must be removed.',
        );
    }

    public function testRootPackageDirectoryRestoresDriveRootSeparator(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => 'C:']);

        self::assertSame(
            'C:' . DIRECTORY_SEPARATOR,
            $this->createManager()->getRootPackageDirForTest(),
            'Drive roots must retain a trailing separator.',
        );
    }

    public function testRootPackageDirectoryTrimsCurrentDirectorySeparator(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => 'assets']);

        MockerState::addCondition(
            'Foxy\\Asset',
            'getcwd',
            [],
            DIRECTORY_SEPARATOR,
        );

        self::assertSame(
            DIRECTORY_SEPARATOR . 'assets',
            $this->createManager()->getRootPackageDirForTest(),
            'The root path must contain one separator.',
        );
    }

    public function testRootPathsDoNotDuplicateDirectorySeparator(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => DIRECTORY_SEPARATOR]);

        $manager = $this->createManager();

        self::assertSame(
            DIRECTORY_SEPARATOR . 'inspectable.lock',
            $manager->getLockFilePathForTest(),
            'The lock path must have one leading separator.',
        );
        self::assertSame(
            DIRECTORY_SEPARATOR . 'node_modules',
            $manager->getNodeModulesPathForTest(),
            'The modules path must have one leading separator.',
        );
    }

    public function testRunStreamsOutputFromConfiguredRootDirectory(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'web';

        $this->sfs->mkdir($rootPackageDir);

        $this->config = new Config(
            [],
            ['root-package-json-dir' => $rootPackageDir, 'run-asset-manager' => true],
        );

        $position = 0;

        $executor = $this->createMock(ProcessExecutor::class);

        $executor
            ->expects(self::exactly(2))
            ->method('execute')
            ->willReturnCallback(
                static function (mixed $command, mixed &$output = null, mixed $cwd = null) use (
                    &$position,
                    $rootPackageDir,
                ): int {
                    self::assertSame(
                        $rootPackageDir,
                        $cwd,
                        'Commands must run from the configured root directory.',
                    );

                    if (0 === $position++) {
                        self::assertSame(
                            'inspectable --version',
                            $command,
                            'Version lookup must use the configured binary.',
                        );
                        $output = '42.0.0';

                        return 0;
                    }

                    self::assertSame(
                        'inspectable install',
                        $command,
                        'The install command must follow version lookup.',
                    );
                    self::assertIsCallable($output, 'Streaming output must be delivered through a callback.');

                    $output('out', 'standard output');
                    $output('err', 'error output');

                    return 0;
                },
            );

        $this->io
            ->expects(self::once())
            ->method('writeRaw')
            ->with('standard output', false);
        $this->io
            ->expects(self::once())
            ->method('writeErrorRaw')
            ->with('error output', false);

        $manager = new InspectableAssetManager($this->io, $this->config, $executor, $this->fs, $this->fallback);

        self::assertSame(
            0,
            $manager->run(),
            'Successful execution must return a zero exit code.',
        );
    }

    public function testVersionCommandUsesConfiguredRootDirectory(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'web';

        $this->sfs->mkdir($rootPackageDir);

        $this->config = new Config([], ['root-package-json-dir' => $rootPackageDir]);

        $observedDirectory = null;

        $executor = $this->createMock(ProcessExecutor::class);

        $executor
            ->expects(self::once())
            ->method('execute')
            ->willReturnCallback(
                static function (mixed $command, mixed &$output = null, mixed $cwd = null) use (
                    &$observedDirectory,
                ): int {
                    $output = '42.0.0';
                    $observedDirectory = $cwd;

                    return 0;
                },
            );

        $manager = new InspectableAssetManager($this->io, $this->config, $executor, $this->fs, $this->fallback);

        $manager->validate();

        self::assertSame(
            $rootPackageDir,
            $observedDirectory,
            'Version lookup must run from the configured root directory.',
        );
    }

    public function testVersionLookupRemainsExtensibleWhenConverterIsDisabled(): void
    {
        $manager = $this->createManager();

        $manager->disableVersionConverterForTest();

        self::assertSame(
            '',
            $manager->getVersionForTest(),
            'A disabled converter must preserve the empty version.',
        );
        self::assertNull(
            $this->executor->getLastCommand(),
            'A disabled converter must skip process execution.',
        );
    }

    public function testVersionOutputHookResultIsConverted(): void
    {
        $converter = $this->createMock(VersionConverterInterface::class);

        $converter
            ->expects(self::once())
            ->method('convertVersion')
            ->with('42.0.0')
            ->willReturn('42.0.0');

        $this->executor->addExpectedValues(0, "  inspectable 42.0.0\n");

        $manager = $this->createManager($converter);

        $manager->setNormalizedVersionOutputForTest('42.0.0');

        self::assertSame(
            '42.0.0',
            $manager->getVersionForTest(),
            'The converted version must be returned.',
        );
        self::assertSame(
            'inspectable 42.0.0',
            $manager->getVersionOutputForTest(),
            'Normalized command output must be retained.',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new Config([]);
        $this->io = $this->createMock(IOInterface::class);
        $this->executor = new ProcessExecutorMock($this->io);
        $this->fs = $this->createMock(Filesystem::class);
        $this->fallback = $this->createMock(FallbackInterface::class);
        $this->rootPackage = $this->createMock(RootPackageInterface::class);
        $this->rootPackage->method('getLicense')->willReturn([]);
        $this->sfs = new SymfonyFilesystem();
        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_abstract_asset_manager_test_', true);
        $this->sfs->mkdir($this->cwd);

        chdir($this->cwd);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);

        $this->sfs->remove($this->cwd);
        $this->config = null;
        $this->cwd = null;
        $this->executor = null;
        $this->fallback = null;
        $this->fs = null;
        $this->io = null;
        $this->oldCwd = null;
        $this->rootPackage = null;
        $this->sfs = null;
    }

    private function createManager(
        VersionConverterInterface|null $versionConverter = null,
    ): InspectableAssetManager {
        return new InspectableAssetManager(
            $this->io,
            $this->config,
            $this->executor,
            $this->fs,
            $this->fallback,
            $versionConverter,
        );
    }
}
