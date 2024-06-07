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
use Foxy\Asset\AbstractAssetManager;
use Foxy\Asset\AssetManagerInterface;
use Foxy\Config\Config;
use Foxy\Fallback\FallbackInterface;
use Foxy\Tests\Fixtures\Util\ProcessExecutorMock;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Abstract class for asset manager tests.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
abstract class AssetManager extends \PHPUnit\Framework\TestCase
{
    protected Config|null $config = null;
    protected IOInterface|null $io = null;
    protected ProcessExecutorMock|null $executor = null;
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
        $this->cwd = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . uniqid('foxy_asset_manager_test_', true);
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

        $lockFilePath = $this->cwd . \DIRECTORY_SEPARATOR . $this->manager->getLockPackageName();

        \file_put_contents($lockFilePath, '{}');

        $this->assertFileExists($lockFilePath);
        $this->assertTrue($this->manager->isInstalled());
        $this->assertTrue($this->manager->isUpdatable());

        $assetPackage = $this->manager->addDependencies($rootPackage, $allDependencies);

        $this->assertInstanceOf(\Foxy\Asset\AssetPackageInterface::class, $assetPackage);
        $this->assertEquals($expectedPackage, $assetPackage->getPackage());
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

            \file_put_contents($this->cwd . \DIRECTORY_SEPARATOR . $this->manager->getPackageName(), '{}');

            $nodeModulePath = $this->cwd . ltrim(AbstractAssetManager::NODE_MODULES_PATH, '.');

            $this->sfs->mkdir($nodeModulePath);
            $this->assertFileExists($nodeModulePath);

            $lockFilePath = $this->cwd . \DIRECTORY_SEPARATOR . $this->manager->getLockPackageName();

            \file_put_contents($lockFilePath, '{}');

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

    public function testSpecifyCustomDirectoryFromPackageJson(): void
    {
        $this->config = new Config(
            [],
            ['run-asset-manager' => true, 'root-package-json-dir' => $this->cwd],
        );
        $this->manager = $this->getManager();

        $this->assertSame($this->cwd, $this->config->get('root-package-json-dir'));
        $this->assertSame(0, $this->getManager()->run());
    }

    public function testSpecifyCustomDirectoryFromPackageJsonException(): void
    {
        $this->expectException(\Foxy\Exception\RuntimeException::class);
        $this->expectExceptionMessage('The root package directory "path/to/invalid" doesn\'t exist.');

        $this->config = new Config(
            [],
            ['run-asset-manager' => true, 'root-package-json-dir' => 'path/to/invalid'],
        );
        $this->manager = $this->getManager();

        $this->assertSame(0, $this->getManager()->run());
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
