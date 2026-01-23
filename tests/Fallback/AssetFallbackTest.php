<?php

declare(strict_types=1);

namespace Foxy\Tests\Fallback;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Foxy\Config\Config;
use Foxy\Exception\RuntimeException;
use Foxy\Fallback\AssetFallback;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Xepozz\InternalMocker\MockerState;

use function chdir;
use function file_put_contents;

use const DIRECTORY_SEPARATOR;

final class AssetFallbackTest extends TestCase
{
    protected AssetFallback|null $assetFallback = null;
    private Config|null $config = null;
    private string|null $cwd = '';
    private Filesystem|MockObject|null $fs = null;
    private IOInterface|MockObject|null $io = null;
    private string|null $oldCwd = '';
    private \Symfony\Component\Filesystem\Filesystem|null $sfs = null;

    public static function getRestoreData(): array
    {
        return [[true], [false]];
    }

    public static function getSaveData(): array
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

        $this->io->expects(self::once())->method('write');

        $this->fs->expects(self::once())->method('remove')->with('package.json');

        $this->assetFallback->save();
        $this->assetFallback->restore();

        if ($withPackageFile) {
            self::assertFileExists($path);
            self::assertSame($content, file_get_contents($path));
        } else {
            self::assertFileDoesNotExist($path);
        }
    }

    public function testRestoreThrowsWhenRemoveFails(): void
    {
        $path = $this->cwd . '/package.json';
        file_put_contents($path, '{}');

        $this->io->expects(self::once())->method('write');

        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->with('package.json')
            ->willThrowException(new RuntimeException('Remove failed.'));

        $this->assetFallback->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to remove fallback asset file "package.json".');

        try {
            $this->assetFallback->restore();
        } catch (RuntimeException $exception) {
            $previous = $exception->getPrevious();

            self::assertInstanceOf(\RuntimeException::class, $previous);
            self::assertSame('Remove failed.', $previous->getMessage());

            throw $exception;
        }
    }

    public function testRestoreThrowsWhenWriteFails(): void
    {
        $content = '{}';
        $path = $this->cwd . '/package.json';

        file_put_contents($path, $content);

        $this->io->expects(self::once())->method('write');

        $this->fs->expects(self::once())->method('remove')->with('package.json');

        $this->assetFallback->save();

        MockerState::addCondition('Foxy\\Fallback', 'file_put_contents', ['package.json', $content, 0, null], false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to write fallback asset file "package.json".');

        $this->assetFallback->restore();
    }

    public function testRestoreWithDisableOption(): void
    {
        $config = new Config(['fallback-asset' => false]);
        $assetFallback = new AssetFallback($this->io, $config, 'package.json', $this->fs);

        $this->io->expects(self::never())->method('write');

        $this->fs->expects(self::never())->method('remove');

        $assetFallback->restore();
    }

    /**
     * @dataProvider getSaveData
     */
    public function testSave(bool $withPackageFile): void
    {
        if ($withPackageFile) {
            file_put_contents($this->cwd . '/package.json', '{}');
        }

        self::assertInstanceOf(AssetFallback::class, $this->assetFallback->save());
    }

    public function testSaveThrowsWhenFileCannotBeRead(): void
    {
        $path = $this->cwd . '/package.json';

        file_put_contents($path, '{}');
        self::assertFileExists($path);

        MockerState::addCondition('Foxy\\Fallback', 'file_get_contents', ['package.json', false, null, 0, null], false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read fallback asset file "package.json".');

        $this->assetFallback->save();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_asset_fallback_test_', true);
        $this->config = new Config(['fallback-asset' => true]);
        $this->io = $this->createMock(IOInterface::class);
        $this->fs = $this->createMock(Filesystem::class);
        $this->sfs = new \Symfony\Component\Filesystem\Filesystem();
        $this->sfs->mkdir($this->cwd);

        chdir($this->cwd);

        $this->assetFallback = new AssetFallback($this->io, $this->config, 'package.json', $this->fs);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);

        $this->sfs->remove($this->cwd);
        $this->config = null;
        $this->io = null;
        $this->fs = null;
        $this->sfs = null;
        $this->assetFallback = null;
        $this->oldCwd = null;
        $this->cwd = null;
    }
}
