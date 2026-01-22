<?php

declare(strict_types=1);

/*
 * This file is part of the Foxy package.
 *
 * (c) François Pluchino <francois.pluchino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Foxy\Tests\Asset;

use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Foxy\Asset\AbstractAssetManager;
use Foxy\Asset\AssetManagerInterface;
use Foxy\Config\Config;
use Foxy\Fallback\FallbackInterface;
use Foxy\Tests\Fixtures\Util\ProcessExecutorMock;
use Foxy\Tests\Fixtures\Util\ThrowingProcessExecutorMock;
use PHPUnit\Framework\MockObject\MockObject;
use Xepozz\InternalMocker\MockerState;

use function file_get_contents;
use function file_put_contents;

use const DIRECTORY_SEPARATOR;

/**
 * Abstract class for asset manager tests.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
abstract class AssetManager extends \PHPUnit\Framework\TestCase
{
    protected Config|null $config = null;
    protected IOInterface|null $io = null;
    protected ProcessExecutorMock|ThrowingProcessExecutorMock|null $executor = null;
    protected Filesystem|MockObject|null $fs = null;
    protected \Symfony\Component\Filesystem\Filesystem|null $sfs = null;
    protected FallbackInterface|MockObject|null $fallback = null;
    protected AssetManagerInterface|null $manager = null;
    protected string|null $oldCwd = '';
    protected string|null $cwd = '';

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

        \chdir($this->cwd);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        \chdir($this->oldCwd);

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

    public function testGetName(): void
    {
        $this->assertSame($this->getValidName(), $this->manager->getName());
    }

    public function testGetLockPackageName(): void
    {
        $this->assertSame($this->getValidLockPackageName(), $this->manager->getLockPackageName());
    }

    public function testGetPackageName(): void
    {
        $this->assertSame('package.json', $this->manager->getPackageName());
    }

    public function testHasLockFile(): void
    {
        $this->assertFalse($this->manager->hasLockFile());
    }

    public function testHasLockFileWithRootPackageDirAsRoot(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => '/']);
        $this->manager = $this->getManager();

        MockerState::addCondition('Foxy\\Asset', 'getcwd', [], $this->cwd);

        $this->assertFalse($this->manager->hasLockFile());
    }

    public function testIsInstalled(): void
    {
        $this->assertFalse($this->manager->isInstalled());
    }

    public function testIsUpdatable(): void
    {
        $this->assertFalse($this->manager->isUpdatable());
    }

    public function testSetUpdatable(): void
    {
        $res = $this->manager->setUpdatable(false);

        $this->assertInstanceOf(AssetManagerInterface::class, $res);
    }

    public function testValidateWithoutInstalledManager(): void
    {
        $this->expectException(\Foxy\Exception\RuntimeException::class);
        $this->expectExceptionMessageMatches('/The binary of "(\w+)" must be installed/');

        $this->manager->validate();
    }

    public function testValidateWithInstalledManagerAndWithoutValidVersion(): void
    {
        $this->expectException(\Foxy\Exception\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/The installed (\w+) version "42.0.0" doesn\'t match with the constraint version ">=50.0"/'
        );

        $this->config = new Config([], ['manager-version' => '>=50.0']);
        $this->manager = $this->getManager();

        $this->executor->addExpectedValues(0, '42.0.0');

        $this->manager->validate();
    }

    public function testValidateWithInstalledManagerAndWithValidVersion(): void
    {
        $this->config = new Config([], ['manager-version' => '>=41.0']);
        $this->manager = $this->getManager();

        $this->executor->addExpectedValues(0, '42.0.0');

        $this->manager->validate();
        $this->assertSame('>=41.0', $this->config->get('manager-version'));
    }

    public function testValidateWithInstalledManagerAndWithoutValidationVersion(): void
    {
        $this->executor->addExpectedValues(0, '42.0.0');

        $this->manager->validate();
        $this->assertNull($this->config->get('manager-version'));
    }

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

        /** @var MockObject|RootPackageInterface $rootPackage */
        $rootPackage = $this->createMock(RootPackageInterface::class);

        $rootPackage->expects($this->any())->method('getLicense')->willReturn([]);

        $this->assertFalse($this->manager->isInstalled());
        $this->assertFalse($this->manager->isUpdatable());

        $assetPackage = $this->manager->addDependencies($rootPackage, $allDependencies);

        $this->assertInstanceOf(\Foxy\Asset\AssetPackageInterface::class, $assetPackage);

        $this->assertEquals($expectedPackage, $assetPackage->getPackage());
    }

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

        /** @var MockObject|RootPackageInterface $rootPackage */
        $rootPackage = $this->createMock(RootPackageInterface::class);

        $rootPackage->expects($this->any())->method('getLicense')->willReturn([]);

        $nodeModulePath = $this->cwd . ltrim(AbstractAssetManager::NODE_MODULES_PATH, '.');

        $jsonFile->write($package);

        $this->assertFileExists($jsonFile->getPath());
        $this->sfs->mkdir($nodeModulePath);
        $this->assertFileExists($nodeModulePath);

        $lockFilePath = $this->cwd . DIRECTORY_SEPARATOR . $this->manager->getLockPackageName();

        file_put_contents($lockFilePath, '{}');

        $this->assertFileExists($lockFilePath);
        $this->assertTrue($this->manager->isInstalled());
        $this->assertTrue($this->manager->isUpdatable());

        $assetPackage = $this->manager->addDependencies($rootPackage, $allDependencies);

        $this->assertInstanceOf(\Foxy\Asset\AssetPackageInterface::class, $assetPackage);
        $this->assertEquals($expectedPackage, $assetPackage->getPackage());
    }

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

        /** @var MockObject|RootPackageInterface $rootPackage */
        $rootPackage = $this->createMock(RootPackageInterface::class);

        $rootPackage->expects($this->any())->method('getLicense')->willReturn([]);
        $this->manager->addDependencies($rootPackage, $dependencies);

        $this->assertSame($cwdPackageContent, file_get_contents($cwdPackagePath));

        $updatedContent = (string) file_get_contents($rootPackagePath);

        $this->assertStringContainsString(
            '"@composer-asset/new--dependency": "file:./path/new/dependency"',
            $updatedContent
        );
        $this->assertMatchesRegularExpression(
            '/\n {4}"dependencies": \{/',
            $updatedContent,
        );
        $this->assertMatchesRegularExpression(
            '/\n {8}"@composer-asset\/new--dependency": "file:\.\/path\/new\/dependency"/',
            $updatedContent
        );
    }

    public function testRunWithDisableOption(): void
    {
        $this->config = new Config([], ['run-asset-manager' => false]);

        $this->assertSame(0, $this->getManager()->run());
    }

    public static function getRunData(): array
    {
        return [[0, 'install'], [0, 'update'], [1, 'install'], [1, 'update']];
    }

    /**
     * @dataProvider getRunData
     */
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
            $this->assertFileExists($nodeModulePath);

            $lockFilePath = $this->cwd . DIRECTORY_SEPARATOR . $this->manager->getLockPackageName();

            file_put_contents($lockFilePath, '{}');

            $this->assertFileExists($lockFilePath);
            $this->assertTrue($this->manager->isInstalled());
            $this->assertTrue($this->manager->isUpdatable());
        }

        if (0 === $expectedRes) {
            $this->fallback->expects($this->never())->method('restore');
        } else {
            $this->fallback->expects($this->once())->method('restore');
        }

        $this->executor->addExpectedValues($expectedRes, 'ASSET MANAGER OUTPUT');

        $this->assertSame($expectedRes, $this->getManager()->run());
        $this->assertSame($expectedCommand, $this->executor->getLastCommand());
        $this->assertSame('ASSET MANAGER OUTPUT', $this->executor->getLastOutput());
    }

    public function testRunRestoresTimeoutWhenExecutorThrows(): void
    {
        $originalTimeout = ProcessExecutor::getTimeout();
        $expectedTimeout = 42;
        $managerTimeout = 900;

        ProcessExecutor::setTimeout($expectedTimeout);

        try {
            $this->executor = new ThrowingProcessExecutorMock($this->io);
            $this->config = new Config([], ['run-asset-manager' => true, 'manager-timeout' => $managerTimeout]);
            $this->manager = $this->getManager();

            try {
                $this->manager->run();
                $this->fail('Expected a runtime exception when execute fails.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Process execution failed.', $exception->getMessage());
            }

            $this->assertSame($expectedTimeout, ProcessExecutor::getTimeout());
        } finally {
            ProcessExecutor::setTimeout($originalTimeout);
        }
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

        $this->assertSame($rootPackageDir, $this->config->get('root-package-json-dir'));
        $this->assertSame(0, $this->getManager()->run());
        $this->assertSame($originalCwd, getcwd());
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

        try {
            $this->getManager()->run();
            $this->fail('Expected a runtime exception for invalid root package directory.');
        } catch (\Foxy\Exception\RuntimeException $exception) {
            $this->assertSame(
                sprintf('The root package directory "%s" doesn\'t exist.', $expectedPath),
                $exception->getMessage()
            );
            $this->assertSame($originalCwd, getcwd());
        }
    }

    public function testRunWithGetcwdFailure(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'root-package';
        $this->sfs->mkdir($rootPackageDir);
        $originalCwd = \getcwd();

        $this->config = new Config(
            [],
            ['run-asset-manager' => true, 'root-package-json-dir' => $rootPackageDir],
        );
        $this->manager = $this->getManager();

        MockerState::addCondition('Foxy\\Asset', 'getcwd', [], false);

        try {
            $this->getManager()->run();
            $this->fail('Expected a runtime exception when getcwd fails.');
        } catch (\Foxy\Exception\RuntimeException $exception) {
            $this->assertSame('Unable to get the current working directory.', $exception->getMessage());
            $this->assertSame($originalCwd, \getcwd());
        }
    }

    public function testHasLockFileWithRelativeRootPackageDirAndGetcwdFailure(): void
    {
        $this->config = new Config([], ['root-package-json-dir' => 'root-package']);
        $this->manager = $this->getManager();

        MockerState::addCondition('Foxy\\Asset', 'getcwd', [], false);

        $this->expectException(\Foxy\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Unable to get the current working directory.');

        $this->manager->hasLockFile();
    }

    public function testHasLockFileWithoutRootPackageDirAndGetcwdFailure(): void
    {
        $this->config = new Config([]);
        $this->manager = $this->getManager();

        MockerState::addCondition('Foxy\\Asset', 'getcwd', [], false);

        $this->expectException(\Foxy\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Unable to get the current working directory.');

        $this->manager->hasLockFile();
    }

    public function testRunWithChdirFailure(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'root-package';
        $this->sfs->mkdir($rootPackageDir);
        $originalCwd = \getcwd();

        $this->config = new Config(
            [],
            ['run-asset-manager' => true, 'root-package-json-dir' => $rootPackageDir],
        );
        $this->manager = $this->getManager();

        MockerState::addCondition('Foxy\\Asset', 'chdir', [$rootPackageDir], false);

        try {
            $this->getManager()->run();
            $this->fail('Expected a runtime exception when chdir fails.');
        } catch (\Foxy\Exception\RuntimeException $exception) {
            $this->assertSame(
                sprintf('Unable to change working directory to "%s".', $rootPackageDir),
                $exception->getMessage()
            );
            $this->assertSame($originalCwd, \getcwd());
        }
    }

    public function testRunWithChdirRestoreFailure(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'root-package';
        $this->sfs->mkdir($rootPackageDir);
        $originalCwd = \getcwd();

        $this->config = new Config(
            [],
            ['run-asset-manager' => true, 'root-package-json-dir' => $rootPackageDir],
        );
        $this->manager = $this->getManager();

        MockerState::addCondition('Foxy\\Asset', 'chdir', [$rootPackageDir], true);
        MockerState::addCondition('Foxy\\Asset', 'chdir', [$originalCwd], false);

        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        try {
            $this->manager->run();
            $this->fail('Expected a runtime exception when restoring chdir fails.');
        } catch (\Foxy\Exception\RuntimeException $exception) {
            $this->assertSame(
                sprintf('Unable to restore working directory to "%s".', $originalCwd),
                $exception->getMessage()
            );
            $this->assertSame($originalCwd, \getcwd());
        }
    }

    abstract protected function getManager(): AssetManagerInterface;

    abstract protected function getValidName(): string;

    abstract protected function getValidLockPackageName(): string;

    abstract protected function getValidVersionCommand(): string;

    abstract protected function getValidInstallCommand(): string;

    abstract protected function getValidUpdateCommand(): string;

    protected function actionForTestAddDependenciesForUpdateCommand(): void
    {
        // do nothing by default
    }

    /**
     * @param string $action The action
     */
    protected function actionForTestRunForInstallCommand(string $action): void
    {
        // do nothing by default
    }
}
