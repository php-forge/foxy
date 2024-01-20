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

namespace Foxy\Tests\Fallback;

use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryManager;
use Composer\Util\Filesystem;
use Foxy\Config\Config;
use Foxy\Fallback\ComposerFallback;
use Foxy\Util\LockerUtil;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Tests for composer fallback.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class ComposerFallbackTest extends \PHPUnit\Framework\TestCase
{
    private Config|null $config = null;
    private Composer|MockObject|null $composer = null;
    private IOInterface|MockObject|null $io = null;
    private InputInterface|MockObject|null $input = null;
    private Filesystem|MockObject|null $fs = null;
    private Installer|MockObject|null $installer = null;
    private \Symfony\Component\Filesystem\Filesystem|null $sfs = null;
    private string|null $oldCwd = '';
    private string|null $cwd = '';
    private ComposerFallback|null $composerFallback = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . uniqid('foxy_composer_fallback_test_', true);
        $this->config = new Config(['fallback-composer' => true]);
        $this->composer = $this->createMock(Composer::class);
        $this->io = $this->createMock(IOInterface::class);
        $this->input = $this->createMock(InputInterface::class);
        $this->fs = $this->createMock(Filesystem::class);
        /** @var Installer|MockObject */
        $this->installer = $this
            ->getMockBuilder(Installer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['run'])
            ->getMock();
        $this->sfs = new \Symfony\Component\Filesystem\Filesystem();
        $this->sfs->mkdir($this->cwd);

        \chdir($this->cwd);

        $this->composerFallback = new ComposerFallback(
            $this->composer,
            $this->io,
            $this->config,
            $this->input,
            $this->fs,
            $this->installer
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        \chdir($this->oldCwd);

        $this->sfs->remove($this->cwd);
        $this->config = null;
        $this->composer = null;
        $this->io = null;
        $this->input = null;
        $this->fs = null;
        $this->installer = null;
        $this->sfs = null;
        $this->composerFallback = null;
        $this->oldCwd = null;
        $this->cwd = null;
    }

    public static function getSaveData(): array
    {
        return [[true], [false]];
    }

    /**
     * @dataProvider getSaveData
     */
    public function testSave(bool $withLockFile): void
    {
        $rm = $this->createMock(RepositoryManager::class);

        $this->composer->expects($this->any())->method('getRepositoryManager')->willReturn($rm);

        $im = $this->createMock(InstallationManager::class);

        $this->composer->expects($this->any())->method('getInstallationManager')->willReturn($im);

        \file_put_contents($this->cwd . '/composer.json', '{}');

        if ($withLockFile) {
            \file_put_contents($this->cwd . '/composer.lock', json_encode(['content-hash' => 'HASH_VALUE']));
        }

        $this->assertInstanceOf(ComposerFallback::class, $this->composerFallback->save());
    }

    public function testRestoreWithDisableOption(): void
    {
        $config = new Config(['fallback-composer' => false]);
        $composerFallback = new ComposerFallback($this->composer, $this->io, $config, $this->input);

        $this->io->expects($this->never())->method('write');

        $composerFallback->restore();
    }

    public static function getRestoreData(): array
    {
        return [[[]], [[['name' => 'foo/bar', 'version' => '1.0.0.0']]]];
    }

    /**
     * @dataProvider getRestoreData
     */
    public function testRestore(array $packages): void
    {
        $composerFile = 'composer.json';
        $composerContent = '{}';
        $lockFile = 'composer.lock';
        $vendorDir = $this->cwd . '/vendor/';

        \file_put_contents($this->cwd . '/' . $composerFile, $composerContent);
        \file_put_contents(
            $this->cwd . '/' . $lockFile,
            json_encode(
                [
                    'content-hash' => 'HASH_VALUE',
                    'packages' => $packages,
                    'packages-dev' => [],
                    'prefer-stable' => true,
                ]
            )
        );

        $this->input
            ->expects($this->any())
            ->method('getOption')
            ->willReturnCallback(fn ($option) => 'verbose' === $option ? false : null);

        $ed = $this->createMock(EventDispatcher::class);

        $this->composer->expects($this->any())->method('getEventDispatcher')->willReturn($ed);

        $rm = $this->createMock(RepositoryManager::class);

        $this->composer->expects($this->any())->method('getRepositoryManager')->willReturn($rm);

        $im = $this->createMock(InstallationManager::class);

        $this->composer->expects($this->any())->method('getInstallationManager')->willReturn($im);
        $this->io->expects($this->once())->method('write');

        $locker = LockerUtil::getLocker($this->io, $im, $composerFile);

        $this->composer->expects($this->atLeastOnce())->method('getLocker')->willReturn($locker);

        $config = $this->getMockBuilder(\Composer\Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();

        $this->composer->expects($this->atLeastOnce())->method('getConfig')->willReturn($config);

        $config
            ->expects($this->atLeastOnce())
            ->method('get')
            ->willReturnCallback(fn ($key, $default = null) => 'vendor-dir' === $key ? $vendorDir : $default);

        if (0 === \count($packages)) {
            $this->fs->expects($this->once())->method('remove')->with($vendorDir);
        } else {
            $this->fs->expects($this->never())->method('remove');
            $this->installer->expects($this->once())->method('run');
        }

        $this->composerFallback->save();
        $this->composerFallback->restore();
    }
}
