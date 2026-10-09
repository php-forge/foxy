<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\RootPackageInterface;
use Composer\Util\{Filesystem, ProcessExecutor};
use Foxy\Asset\{AbstractAssetManager, AssetManagerInterface, AssetPackageInterface};
use Foxy\Config\Config;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Fallback\FallbackInterface;
use Foxy\Tests\Fixtures\Util\{ProcessExecutorMock, ThrowingProcessExecutorMock};
use Foxy\Tests\Provider\AssetManagerProvider;
use PHPUnit\Framework\Attributes\{DataProviderExternal, RequiresOperatingSystemFamily};
use PHPUnit\Framework\MockObject\{Exception, MockObject};
use PHPUnit\Framework\TestCase;
use Xepozz\InternalMocker\MockerState;

use function chdir;
use function file_get_contents;
use function file_put_contents;
use function getcwd;

use const DIRECTORY_SEPARATOR;

/**
 * Base unit tests for {@see AbstractAssetManager} subclasses, shared by every concrete asset manager test.
 *
 * {@see AssetManagerProvider} for test case data providers.
 */
abstract class AssetManager extends TestCase
{
    protected Config|null $config = null;
    protected string|null $cwd = '';
    protected ProcessExecutorMock|ThrowingProcessExecutorMock|null $executor = null;
    protected FallbackInterface|MockObject|null $fallback = null;
    protected Filesystem|MockObject|null $fs = null;
    protected IOInterface|MockObject|null $io = null;
    protected AssetManagerInterface|null $manager = null;
    protected string|null $oldCwd = '';
    protected \Symfony\Component\Filesystem\Filesystem|null $sfs = null;

    abstract protected function getManager(): AssetManagerInterface;
    abstract protected function getUnsupportedVersion(): string;
    abstract protected function getValidInstallCommand(): string;
    abstract protected function getValidLockPackageName(): string;
    abstract protected function getValidName(): string;
    abstract protected function getValidUpdateCommand(): string;
    abstract protected function getValidVersion(): string;
    abstract protected function getValidVersionCommand(): string;
    abstract protected function getValidVersionConstraint(): string;

    /**
     * @throws Exception
     */
    public function testAddDependenciesForInstallCommand(): void
    {
        $expectedPackage = [
            'dependencies' => [
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@composer-asset/new--dependency' => 'file:./path/new/dependency',
            ],
        ];
        $allDependencies = [
            '@composer-asset/foo--bar' => 'path/foo/bar/package.json',
            '@composer-asset/new--dependency' => 'path/new/dependency/package.json',
        ];

        $rootPackage = $this->createMock(RootPackageInterface::class);

        $rootPackage
            ->expects(self::any())
            ->method('getLicense')
            ->willReturn([]);

        self::assertFalse(
            $this->manager->isInstalled(),
            'A missing package must not be marked installed.',
        );
        self::assertFalse(
            $this->manager->isUpdatable(),
            'A missing package must not be marked updatable.',
        );

        $assetPackage = $this->manager->addDependencies($rootPackage, $allDependencies);

        self::assertInstanceOf(
            AssetPackageInterface::class,
            $assetPackage,
            'Dependency merging must return an asset package.',
        );
        self::assertSame(
            $this->getExpectedPackage($expectedPackage),
            $assetPackage->getPackage(),
            'Merged dependencies must match the expected package.',
        );
    }

    /**
     * @throws Exception
     */
    public function testAddDependenciesForUpdateCommand(): void
    {
        $this->actionForTestAddDependenciesForUpdateCommand();

        $expectedPackage = [
            'dependencies' => [
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@composer-asset/new--dependency' => 'file:./path/new/dependency',
            ],
        ];
        $package = [
            'dependencies' => [
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@composer-asset/baz--bar' => 'file:./path/baz/bar',
            ],
        ];
        $allDependencies = [
            '@composer-asset/foo--bar' => 'path/foo/bar/package.json',
            '@composer-asset/new--dependency' => 'path/new/dependency/package.json',
        ];

        $jsonFile = new JsonFile($this->cwd . '/package.json');

        $rootPackage = $this->createMock(RootPackageInterface::class);

        $rootPackage
            ->expects(self::any())
            ->method('getLicense')
            ->willReturn([]);

        $nodeModulePath = $this->cwd . ltrim(AbstractAssetManager::NODE_MODULES_PATH, '.');

        $jsonFile->write($package);

        self::assertFileExists(
            $jsonFile->getPath(),
            'The package manifest must be created.',
        );

        $this->sfs->mkdir($nodeModulePath);

        self::assertFileExists(
            $nodeModulePath,
            'The modules directory must be created.',
        );

        $lockFilePath = $this->cwd . DIRECTORY_SEPARATOR . $this->manager->getLockPackageName();

        file_put_contents($lockFilePath, '{}');

        self::assertFileExists(
            $lockFilePath,
            'The lock file must be created.',
        );
        self::assertTrue(
            $this->manager->isInstalled(),
            'The package must be recognized as installed.',
        );
        self::assertTrue(
            $this->manager->isUpdatable(),
            'The package must be recognized as updatable.',
        );

        $assetPackage = $this->manager->addDependencies($rootPackage, $allDependencies);

        self::assertInstanceOf(
            AssetPackageInterface::class,
            $assetPackage,
            'Dependency merging must return an asset package.',
        );
        self::assertSame(
            $this->getExpectedPackage($expectedPackage),
            $assetPackage->getPackage(),
            'Merged dependencies must match the expected package.',
        );
    }

    /**
     * @throws Exception
     */
    public function testAddDependenciesUsesRootPackageJsonDir(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'root-package';

        $this->sfs->mkdir($rootPackageDir);

        $this->config = new Config([], ['root-package-json-dir' => $rootPackageDir]);

        $this->manager = $this->getManager();

        $rootPackagePath = $rootPackageDir . DIRECTORY_SEPARATOR . $this->manager->getPackageName();
        $cwdPackagePath = $this->cwd . DIRECTORY_SEPARATOR . $this->manager->getPackageName();

        $rootPackageContent = "{\n    \"dependencies\": {\n        \"@composer-asset/foo--bar\": \"file:./path/foo/bar\"\n    }\n}\n";
        $cwdPackageContent = "{\n    \"name\": \"cwd-package\"\n}\n";

        file_put_contents($rootPackagePath, $rootPackageContent);
        file_put_contents($cwdPackagePath, $cwdPackageContent);

        $dependencies = [
            '@composer-asset/foo--bar' => 'path/foo/bar/package.json',
            '@composer-asset/new--dependency' => 'path/new/dependency/package.json',
        ];

        $rootPackage = $this->createMock(RootPackageInterface::class);

        $rootPackage
            ->expects(self::any())
            ->method('getLicense')
            ->willReturn([]);

        $this->manager->addDependencies($rootPackage, $dependencies);

        self::assertSame(
            $cwdPackageContent,
            file_get_contents($cwdPackagePath),
            'The working-directory manifest must remain unchanged.',
        );

        $updatedContent = (string) file_get_contents($rootPackagePath);

        self::assertStringContainsString(
            '"@composer-asset/new--dependency": "file:../path/new/dependency"',
            $updatedContent,
            'The root manifest must contain the new dependency path.',
        );
        self::assertMatchesRegularExpression(
            '/\n {4}"dependencies": \{/',
            $updatedContent,
            'The dependencies section must preserve its indentation.',
        );
        self::assertMatchesRegularExpression(
            '/\n {8}"@composer-asset\/new--dependency": "file:\.\.\/path\/new\/dependency"/',
            $updatedContent,
            'Dependency entries must preserve their indentation.',
        );
    }

    public function testGetLockPackageName(): void
    {
        self::assertSame(
            $this->getValidLockPackageName(),
            $this->manager->getLockPackageName(),
            'The lock package name must match the expected value.',
        );
    }

    public function testGetName(): void
    {
        self::assertSame(
            $this->getValidName(),
            $this->manager->getName(),
            'The manager name must match the expected value.',
        );
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function testGetPackageJsonPathWithWindowsRootPackageDir(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => 'C:\\']);

        $this->manager = $this->getManager();

        self::assertInstanceOf(
            AbstractAssetManager::class,
            $this->manager,
            'The manager must support absolute package paths.',
        );

        /** @var AbstractAssetManager $manager */
        $manager = $this->manager;

        self::assertSame(
            'C:\\package.json',
            $manager->getPackageJsonPath(),
            'The manifest path must preserve the Windows drive root.',
        );
    }

    public function testGetPackageName(): void
    {
        self::assertSame(
            'package.json',
            $this->manager->getPackageName(),
            'The manifest file name must be `package.json`.',
        );
    }

    public function testGetVersionConstraint(): void
    {
        self::assertSame(
            $this->getValidVersionConstraint(),
            $this->manager->getVersionConstraint(),
            'The version constraint must match the expected value.',
        );
    }

    public function testHasLockFile(): void
    {
        self::assertFalse(
            $this->manager->hasLockFile(),
            'A missing lock file must be reported.',
        );
    }

    public function testHasLockFileWithoutRootPackageDirAndGetcwdFailure(): void
    {
        $this->config = new Config([]);

        $this->manager = $this->getManager();

        MockerState::addCondition(
            'Foxy\\Asset',
            'getcwd',
            [],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::CURRENT_WORKING_DIRECTORY_UNAVAILABLE->getMessage(),
        );

        $this->manager->hasLockFile();
    }

    public function testHasLockFileWithRelativeRootPackageDirAndGetcwdFailure(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => 'root-package']);

        $this->manager = $this->getManager();

        MockerState::addCondition(
            'Foxy\\Asset',
            'getcwd',
            [],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::CURRENT_WORKING_DIRECTORY_UNAVAILABLE->getMessage(),
        );

        $this->manager->hasLockFile();
    }

    public function testHasLockFileWithRootPackageDirAsRoot(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => DIRECTORY_SEPARATOR]);

        $this->manager = $this->getManager();

        MockerState::addCondition(
            'Foxy\\Asset',
            'getcwd',
            [],
            $this->cwd,
        );

        self::assertInstanceOf(
            AbstractAssetManager::class,
            $this->manager,
            'The manager must expose its package path.',
        );

        /** @var AbstractAssetManager $manager */
        $manager = $this->manager;

        self::assertSame(
            DIRECTORY_SEPARATOR . $manager->getPackageName(),
            $manager->getPackageJsonPath(),
            'The root manifest path must contain one leading separator.',
        );
        self::assertFalse(
            $this->manager->hasLockFile(),
            'A missing root lock file must be reported.',
        );
    }

    public function testIsInstalled(): void
    {
        self::assertFalse(
            $this->manager->isInstalled(),
            'A missing installation must be reported.',
        );
    }

    public function testIsInstalledRequiresNodeModulesDirectory(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . $this->manager->getPackageName(), '{}');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . $this->manager->getLockPackageName(), '{}');

        self::assertFalse(
            $this->manager->isInstalled(),
            'Installation requires a modules directory.',
        );
    }

    public function testIsUpdatable(): void
    {
        self::assertFalse(
            $this->manager->isUpdatable(),
            'A missing installation must not be updatable.',
        );
    }

    #[DataProviderExternal(AssetManagerProvider::class, 'runOutcomes')]
    public function testRunForInstallCommand(int $expectedRes, string $action): void
    {
        $this->actionForTestRunForInstallCommand($action);

        $this->config = new Config([], ['run-asset-manager' => true, 'fallback-asset' => true]);

        $this->manager = $this->getManager();

        if ('install' === $action) {
            $expectedCommand = $this->getValidInstallCommand();
        } else {
            $expectedCommand = $this->getValidUpdateCommand();

            file_put_contents($this->cwd . DIRECTORY_SEPARATOR . $this->manager->getPackageName(), '{}');

            $nodeModulePath = $this->cwd . ltrim(AbstractAssetManager::NODE_MODULES_PATH, '.');

            $this->sfs->mkdir($nodeModulePath);

            self::assertFileExists(
                $nodeModulePath,
                'The modules directory must be created.',
            );

            $lockFilePath = $this->cwd . DIRECTORY_SEPARATOR . $this->manager->getLockPackageName();

            file_put_contents($lockFilePath, '{}');

            self::assertFileExists(
                $lockFilePath,
                'The lock file must be created.',
            );
            self::assertTrue(
                $this->manager->isInstalled(),
                'The package must be recognized as installed.',
            );
            self::assertTrue(
                $this->manager->isUpdatable(),
                'The package must be recognized as updatable.',
            );
        }

        if (0 === $expectedRes) {
            $this->fallback->expects(self::never())->method('restore');
        } else {
            $this->fallback->expects(self::once())->method('restore');
        }

        $this->io
            ->expects(self::once())
            ->method('write')
            ->with(
                sprintf(
                    '<info>%s %s dependencies</info>',
                    'update' === $action ? 'Updating' : 'Installing',
                    $this->getValidName(),
                ),
            );

        $this->executor->addExpectedValues($expectedRes, 'ASSET MANAGER OUTPUT');

        self::assertSame(
            $expectedRes,
            $this->getManager()->run(),
            'The process exit status must be preserved.',
        );
        self::assertSame(
            $expectedCommand,
            $this->executor->getLastCommand(),
            'The expected manager command must be executed.',
        );
        self::assertSame(
            'ASSET MANAGER OUTPUT',
            $this->executor->getLastOutput(),
            'The process output must be captured.',
        );
    }

    public function testRunPreservesExecutorFailureWhenFallbackThrows(): void
    {
        $this->executor = new ThrowingProcessExecutorMock($this->io, $this->getValidVersion());
        $this->config = new Config([], ['run-asset-manager' => true]);

        $this->fallback
            ->expects(self::once())
            ->method('restore')
            ->willThrowException(new RuntimeException('Fallback failed.'));

        $this->manager = $this->getManager();

        try {
            $this->manager->run();

            self::fail(
                'Expected fallback restoration to fail.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_MANAGER_FALLBACK_RESTORE_FAILED->getMessage('Fallback failed.'),
                $exception->getMessage(),
                'Fallback failure must be reported.',
            );
            self::assertSame(
                'Process execution failed.',
                $exception->getPrevious()?->getMessage(),
                'The original process failure must be preserved.',
            );
        }
    }

    public function testRunPreservesExitStatusWhenFallbackThrows(): void
    {
        $exitStatus = 7;

        $this->config = new Config([], ['run-asset-manager' => true]);

        $this->fallback
            ->expects(self::once())
            ->method('restore')
            ->willThrowException(new RuntimeException('Fallback failed.'));

        $this->actionForTestRunForInstallCommand('install');
        $this->executor->addExpectedValues($exitStatus, 'ASSET MANAGER OUTPUT');

        $this->manager = $this->getManager();

        try {
            $this->manager->run();

            self::fail(
                'Expected fallback restoration to fail.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_MANAGER_FALLBACK_RESTORE_FAILED->getMessage('Fallback failed.'),
                $exception->getMessage(),
                'Fallback failure must be reported.',
            );

            $previous = $exception->getPrevious();

            self::assertInstanceOf(
                RuntimeException::class,
                $previous,
                'The exit status must be attached to a runtime exception.',
            );
            self::assertSame(
                $exitStatus,
                $previous->getCode(),
                'The original exit status must be preserved.',
            );
            self::assertSame(
                Message::ASSET_MANAGER_EXITED_WITH_STATUS->getMessage($exitStatus),
                $previous->getMessage(),
                'Previous exception must carry the exit status.',
            );
        }
    }

    public function testRunPreservesWorkingDirectoryWhenExecutorThrows(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'root-package';

        $this->sfs->mkdir($rootPackageDir);

        $originalCwd = getcwd();

        $this->executor = new ThrowingProcessExecutorMock($this->io, $this->getValidVersion());
        $this->config = new Config(
            [],
            ['run-asset-manager' => true, 'root-package-json-dir' => $rootPackageDir],
        );

        $this->fallback
            ->expects(self::once())
            ->method('restore');

        $this->manager = $this->getManager();

        try {
            $this->manager->run();

            self::fail(
                'Expected the process execution to fail.',
            );
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Process execution failed.',
                $exception->getMessage(),
                'The original process failure must be reported.',
            );
            self::assertSame(
                $originalCwd,
                getcwd(),
                'The process working directory must be restored.',
            );
        }
    }

    public function testRunRejectsUnsupportedManagerVersion(): void
    {
        $this->config = new Config([], ['run-asset-manager' => true]);

        $this->manager = $this->getManager();

        $this->io
            ->expects(self::never())
            ->method('write');
        $this->fallback
            ->expects(self::never())
            ->method('restore');
        $this->executor->addExpectedValues(0, $this->getUnsupportedVersion());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_VERSION_UNSUPPORTED->getMessage(
                $this->manager->getName(),
                $this->getUnsupportedVersion(),
                $this->getValidVersionConstraint(),
            ),
        );

        $this->manager->run();
    }

    public function testRunRestoresTimeoutWhenExecutorThrows(): void
    {
        $originalTimeout = ProcessExecutor::getTimeout();

        $expectedTimeout = 42;
        $managerTimeout = 900;

        ProcessExecutor::setTimeout($expectedTimeout);

        try {
            $this->executor = new ThrowingProcessExecutorMock($this->io, $this->getValidVersion());
            $this->config = new Config([], ['run-asset-manager' => true, 'manager-timeout' => $managerTimeout]);

            $this->manager = $this->getManager();

            $this->fallback
                ->expects(self::once())
                ->method('restore');

            try {
                $this->manager->run();
                self::fail(
                    'Expected a runtime exception when execute fails.',
                );
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Process execution failed.',
                    $exception->getMessage(),
                    'The original process failure must be reported.',
                );
            }

            self::assertSame(
                $expectedTimeout,
                ProcessExecutor::getTimeout(),
                'The previous process timeout must be restored.',
            );
        } finally {
            ProcessExecutor::setTimeout($originalTimeout);
        }
    }

    public function testRunUsesRelativeRootDirectoryWithoutChangingProcessDirectory(): void
    {
        $configuredRootPackageDir = 'root-package';
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . $configuredRootPackageDir;

        $this->sfs->mkdir($rootPackageDir);

        $originalCwd = getcwd();

        $this->config = new Config(
            [],
            ['run-asset-manager' => true, 'root-package-json-dir' => $configuredRootPackageDir],
        );

        $this->manager = $this->getManager();

        file_put_contents($rootPackageDir . DIRECTORY_SEPARATOR . $this->manager->getPackageName(), '{}');
        file_put_contents($rootPackageDir . DIRECTORY_SEPARATOR . $this->manager->getLockPackageName(), '{}');

        $this->sfs->mkdir($rootPackageDir . DIRECTORY_SEPARATOR . 'node_modules');
        $this->actionForTestRunForInstallCommand('update');
        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        self::assertSame(
            0,
            $this->manager->run(),
            'Successful execution must return a zero exit code.',
        );
        self::assertSame(
            $this->getValidUpdateCommand(),
            $this->executor->getLastCommand(),
            'The update command must be executed.',
        );
        self::assertSame(
            $rootPackageDir,
            $this->executor->getExecutedWorkingDirectory(0),
            'Version lookup must run from the configured directory.',
        );
        self::assertSame(
            $rootPackageDir,
            $this->executor->getExecutedWorkingDirectory(1),
            'The update command must run from the configured directory.',
        );
        self::assertSame(
            $originalCwd,
            getcwd(),
            'The process working directory must remain unchanged.',
        );
    }

    public function testRunWithAbsoluteRootDirectoryDoesNotReadCurrentWorkingDirectory(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'root-package';

        $this->sfs->mkdir($rootPackageDir);

        $this->config = new Config(
            [],
            ['run-asset-manager' => true, 'root-package-json-dir' => $rootPackageDir],
        );

        $this->manager = $this->getManager();

        MockerState::addCondition(
            'Foxy\\Asset',
            'getcwd',
            [],
            false,
        );

        $this->actionForTestRunForInstallCommand('install');
        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        self::assertSame(
            0,
            $this->manager->run(),
            'Successful execution must return a zero exit code.',
        );
        self::assertSame(
            $rootPackageDir,
            $this->executor->getExecutedWorkingDirectory(0),
            'Version lookup must use the absolute root directory.',
        );
        self::assertSame(
            $rootPackageDir,
            $this->executor->getExecutedWorkingDirectory(1),
            'The manager command must use the absolute root directory.',
        );
    }

    #[DataProviderExternal(AssetManagerProvider::class, 'enabledRunAssetManagerValues')]
    public function testRunWithCompatibleEnabledOption(int|string $value): void
    {
        $this->actionForTestRunForInstallCommand('install');

        $this->config = new Config([], ['run-asset-manager' => $value]);

        $this->manager = $this->getManager();

        $this->io
            ->expects(self::once())
            ->method('write')
            ->with(sprintf('<info>Installing %s dependencies</info>', $this->getValidName()));
        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        self::assertSame(
            0,
            $this->manager->run(),
            'Enabled execution must return the process exit code.',
        );
        self::assertSame(
            $this->getValidInstallCommand(),
            $this->executor->getLastCommand(),
            'The install command must be executed.',
        );
    }

    public function testRunWithDisableOption(): void
    {
        $this->config = new Config([], ['run-asset-manager' => false]);

        $this->io
            ->expects(self::never())
            ->method('write');

        self::assertSame(
            0,
            $this->getManager()->run(),
            'Disabled execution must return a zero exit code.',
        );
        self::assertNull(
            $this->executor->getLastCommand(),
            'Disabled execution must not start a process.',
        );
    }

    public function testRunWithoutCustomDirectoryUsesCurrentWorkingDirectory(): void
    {
        $this->config = new Config([], ['run-asset-manager' => true]);

        $this->manager = $this->getManager();

        $this->actionForTestRunForInstallCommand('install');
        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        self::assertSame(
            0,
            $this->manager->run(),
            'Successful execution must return a zero exit code.',
        );
        self::assertSame(
            $this->getValidInstallCommand(),
            $this->executor->getLastCommand(),
            'The install command must be executed.',
        );
        self::assertNull(
            $this->executor->getExecutedWorkingDirectory(0),
            'Version lookup must use the current working directory.',
        );
        self::assertNull(
            $this->executor->getExecutedWorkingDirectory(1),
            'The install command must use the current working directory.',
        );
        self::assertNull(
            $this->executor->getExecutedCommand(2),
            'No unexpected process command must be executed.',
        );
    }

    public function testSetUpdatable(): void
    {
        $res = $this->manager->setUpdatable(false);

        self::assertInstanceOf(
            AssetManagerInterface::class,
            $res,
            'The setter must return the manager instance.',
        );
    }

    public function testSpecifyCustomDirectoryFromPackageJson(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'root-package';

        $this->sfs->mkdir($rootPackageDir);

        $originalCwd = getcwd();

        $this->config = new Config(
            [],
            ['run-asset-manager' => true, 'root-package-json-dir' => $rootPackageDir],
        );

        $this->manager = $this->getManager();

        self::assertSame(
            $rootPackageDir,
            $this->config->get('root-package-json-dir'),
            'The configured package directory must be retained.',
        );

        $this->actionForTestRunForInstallCommand('install');
        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        self::assertSame(
            0,
            $this->getManager()->run(),
            'Successful execution must return a zero exit code.',
        );
        self::assertSame(
            $originalCwd,
            getcwd(),
            'The process working directory must be restored.',
        );
        self::assertSame(
            $rootPackageDir,
            $this->executor->getExecutedWorkingDirectory(0),
            'Version lookup must use the configured directory.',
        );
        self::assertSame(
            $rootPackageDir,
            $this->executor->getExecutedWorkingDirectory(1),
            'The manager command must use the configured directory.',
        );
    }

    public function testSpecifyCustomDirectoryFromPackageJsonException(): void
    {
        $originalCwd = getcwd();

        $expectedPath = $this->cwd . DIRECTORY_SEPARATOR . 'path/to/invalid';

        $this->config = new Config(
            [],
            ['run-asset-manager' => true, 'root-package-json-dir' => 'path/to/invalid'],
        );

        $this->manager = $this->getManager();
        $this->actionForTestRunForInstallCommand('install');

        try {
            $this->getManager()->run();

            self::fail(
                'Expected a runtime exception for invalid root package directory.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_ROOT_PACKAGE_DIR_MISSING->getMessage($expectedPath),
                $exception->getMessage(),
                'Message must name the missing directory.',
            );
            self::assertSame(
                $originalCwd,
                getcwd(),
                'The process working directory must be restored.',
            );
        }
    }

    public function testValidateWithInstalledManagerAndWithoutValidationVersion(): void
    {
        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->manager->validate();

        self::assertNull(
            $this->config->get('manager-version'),
            'No version constraint must be configured by default.',
        );
    }

    public function testValidateWithInstalledManagerAndWithoutValidVersion(): void
    {
        $constraintVersion = '>' . $this->getValidVersion();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_VERSION_CONSTRAINT_MISMATCH->getMessage(
                $this->manager->getName(),
                $this->getValidVersion(),
                $constraintVersion,
            ),
        );

        $this->config = new Config([], ['manager-version' => $constraintVersion]);

        $this->manager = $this->getManager();

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->manager->validate();
    }

    public function testValidateWithInstalledManagerAndWithValidVersion(): void
    {
        $versionConstraint = $this->getValidVersionConstraint();

        $this->config = new Config([], ['manager-version' => $versionConstraint]);

        $this->manager = $this->getManager();

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->manager->validate();

        self::assertSame(
            $versionConstraint,
            $this->config->get('manager-version'),
            'The accepted version constraint must be retained.',
        );
    }

    #[DataProviderExternal(AssetManagerProvider::class, 'nonConcreteManagerVersions')]
    public function testValidateWithNonConcreteManagerVersion(string $reportedVersion, string $convertedVersion): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_VERSION_UNSUPPORTED->getMessage(
                $this->manager->getName(),
                $convertedVersion,
                $this->getValidVersionConstraint(),
            ),
        );

        $this->executor->addExpectedValues(0, $reportedVersion);
        $this->manager->validate();
    }

    public function testValidateWithoutInstalledManager(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_MANAGER_BINARY_NOT_INSTALLED->getMessage($this->manager->getName()),
        );

        $this->manager->validate();
    }

    public function testValidateWithUnsupportedManagerVersion(): void
    {
        $unsupportedVersion = $this->getUnsupportedVersion();
        $versionConstraint = $this->getValidVersionConstraint();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_VERSION_UNSUPPORTED->getMessage(
                $this->manager->getName(),
                $unsupportedVersion,
                $versionConstraint,
            ),
        );

        $this->executor->addExpectedValues(0, $unsupportedVersion);
        $this->manager->validate();
    }

    protected function actionForTestAddDependenciesForUpdateCommand(): void
    {
        // do nothing by default
    }

    /**
     * @param string $action The action
     */
    protected function actionForTestRunForInstallCommand(string $action): void
    {
        $this->executor->addExpectedValues(0, $this->getValidVersion());
    }

    /**
     * @param array $package The asset package expected from the shared dependency fixtures.
     */
    protected function getExpectedPackage(array $package): array
    {
        return $package;
    }

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new Config([]);
        $this->io = $this->createMock(IOInterface::class);
        $this->executor = new ProcessExecutorMock($this->io);
        $this->fs = $this->createMock(Filesystem::class);
        $this->sfs = new \Symfony\Component\Filesystem\Filesystem();
        $this->fallback = $this->createMock(FallbackInterface::class);
        $this->manager = $this->getManager();
        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_asset_manager_test_', true);
        $this->sfs->mkdir($this->cwd);

        chdir($this->cwd);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);

        $this->sfs->remove($this->cwd);
        $this->config = null;
        $this->io = null;
        $this->executor = null;
        $this->fs = null;
        $this->sfs = null;
        $this->fallback = null;
        $this->manager = null;
        $this->oldCwd = null;
        $this->cwd = null;
    }
}
