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

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Foxy\Config\Config;
use Foxy\Fallback\AssetFallback;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for composer fallback.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class AssetFallbackTest extends \PHPUnit\Framework\TestCase
{
    protected AssetFallback|null $assetFallback = null;
    private Config|null $config = null;
    private IOInterface|MockObject|null $io = null;
    private Filesystem|MockObject|null $fs = null;
    private \Symfony\Component\Filesystem\Filesystem|null $sfs = null;
    private string|null $oldCwd = '';
    private string|null $cwd = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . uniqid('foxy_asset_fallback_test_', true);
        $this->config = new Config(['fallback-asset' => true]);
        $this->io = $this->createMock(IOInterface::class);
        $this->fs = $this->createMock(Filesystem::class);
        $this->sfs = new \Symfony\Component\Filesystem\Filesystem();
        $this->sfs->mkdir($this->cwd);

        \chdir($this->cwd);

        $this->assetFallback = new AssetFallback($this->io, $this->config, 'package.json', $this->fs);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        \chdir($this->oldCwd);

        $this->sfs->remove($this->cwd);
        $this->config = null;
        $this->io = null;
        $this->fs = null;
        $this->sfs = null;
        $this->assetFallback = null;
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
    public function testSave(bool $withPackageFile): void
    {
        if ($withPackageFile) {
            \file_put_contents($this->cwd . '/package.json', '{}');
        }

        $this->assertInstanceOf(AssetFallback::class, $this->assetFallback->save());
    }

    public function testRestoreWithDisableOption(): void
    {
        $config = new Config(['fallback-asset' => false]);
        $assetFallback = new AssetFallback($this->io, $config, 'package.json', $this->fs);

        $this->io->expects($this->never())->method('write');

        $this->fs->expects($this->never())->method('remove');

        $assetFallback->restore();
    }

    public static function getRestoreData(): array
    {
        return [[true], [false]];
    }

    /**
     * @dataProvider getRestoreData
     */
    public function testRestore(bool $withPackageFile): void
    {
        $content = '{}';
        $path = $this->cwd . '/package.json';

        if ($withPackageFile) {
            file_put_contents($path, $content);
        }

        $this->io->expects($this->once())->method('write');

        $this->fs->expects($this->once())->method('remove')->with('package.json');

        $this->assetFallback->save();
        $this->assetFallback->restore();

        if ($withPackageFile) {
            $this->assertFileExists($path);
            $this->assertSame($content, file_get_contents($path));
        } else {
            $this->assertFileDoesNotExist($path);
        }
    }
}
