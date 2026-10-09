<?php

declare(strict_types=1);

namespace Foxy\Tests\Fallback;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Foxy\Config\Config;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Fallback\AssetFallback;
use Foxy\Tests\Provider\AssetFallbackProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Xepozz\InternalMocker\MockerState;

use function chdir;
use function file_put_contents;

use const DIRECTORY_SEPARATOR;

/**
 * Unit tests for {@see AssetFallback} asset manifest snapshot and restore.
 *
 * {@see AssetFallbackProvider} for test case data providers.
 */
final class AssetFallbackTest extends TestCase
{
    protected AssetFallback|null $assetFallback = null;
    private Config|null $config = null;
    private string|null $cwd = '';
    private Filesystem|MockObject|null $fs = null;
    private IOInterface|MockObject|null $io = null;
    private string|null $oldCwd = '';
    private \Symfony\Component\Filesystem\Filesystem|null $sfs = null;

    public function testIntegerOneEnablesFallback(): void
    {
        $path = "{$this->cwd}/package.json";

        file_put_contents($path, '{"original":true}');

        $config = new Config(['fallback-asset' => 1]);
        $assetFallback = new AssetFallback($this->io, $config, 'package.json', $this->fs);

        $assetFallback->save();

        file_put_contents($path, '{"changed":true}');

        $assetFallback->restore();

        self::assertSame(
            '{"original":true}',
            file_get_contents($path),
            'Restored content must match the original snapshot.',
        );
    }

    #[DataProviderExternal(AssetFallbackProvider::class, 'originalManifests')]
    public function testRestore(string|null $originalContent): void
    {
        $path = $this->cwd . '/package.json';

        if (null !== $originalContent) {
            file_put_contents($path, $originalContent);
        }

        $this->assetFallback->save();

        file_put_contents($path, '{"changed":true}');

        $this->io
            ->expects(self::once())
            ->method('write');

        if (null === $originalContent) {
            $this->fs
                ->expects(self::once())
                ->method('remove')
                ->with('package.json')
                ->willReturnCallback(function (string $file): bool {
                    $this->sfs->remove($file);

                    return true;
                });
        } else {
            $this->fs
                ->expects(self::never())
                ->method('remove');
        }

        $this->assetFallback->restore();

        if (null !== $originalContent) {
            self::assertFileExists(
                $path,
                'Original manifest file must exist after restore.',
            );
            self::assertSame(
                $originalContent,
                file_get_contents($path),
                'Restored content must match the original snapshot.',
            );
        } else {
            self::assertFileDoesNotExist(
                $path,
                'Manifest file must not exist if there was no original content.',
            );
        }
    }

    public function testRestoreBeforeSaveDoesNothing(): void
    {
        $path = "{$this->cwd}/package.json";

        file_put_contents($path, '{"current":true}');

        $this->io
            ->expects(self::never())
            ->method('write');
        $this->fs
            ->expects(self::never())
            ->method('remove');

        $this->assetFallback->restore();

        self::assertSame(
            '{"current":true}',
            file_get_contents($path),
            'Manifest content must remain unchanged if restore is called before save.',
        );
    }

    public function testRestoreDoesNotRemoveDirectoryCreatedAfterSnapshot(): void
    {
        $path = "{$this->cwd}/package.json";
        $sentinel = "{$path}/keep.txt";

        $this->assetFallback->save();
        $this->sfs->mkdir($path);

        file_put_contents($sentinel, 'keep');

        $this->io
            ->expects(self::once())
            ->method('write');
        $this->fs
            ->expects(self::never())
            ->method('remove');

        try {
            $this->assetFallback->restore();
            self::fail(
                'Expected restore to reject a non-file manifest path.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::FALLBACK_ASSET_PATH_NOT_FILE->getMessage('package.json'),
                $exception->getMessage(),
                'Message must name the non-regular manifest path.',
            );
        }

        self::assertFileExists(
            $sentinel,
            'Sentinel file must exist after restore attempt.',
        );
    }

    public function testRestoreThrowsWhenRemoveFails(): void
    {
        $this->assetFallback->save();

        file_put_contents("{$this->cwd}/package.json", '{}');

        $this->io->expects(self::once())->method('write');

        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->with('package.json')
            ->willThrowException(new RuntimeException('Remove failed.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::FALLBACK_ASSET_REMOVE_FAILED->getMessage('package.json'),
        );

        try {
            $this->assetFallback->restore();
        } catch (RuntimeException $exception) {
            $previous = $exception->getPrevious();

            self::assertInstanceOf(
                \RuntimeException::class,
                $previous,
                'Previous exception must be a RuntimeException.',
            );
            self::assertSame(
                'Remove failed.',
                $previous->getMessage(),
                'Previous exception message must match the thrown exception.',
            );
            self::assertSame(
                0,
                $exception->getCode(),
                "Exception code must be '0'.",
            );

            throw $exception;
        }
    }

    public function testRestoreThrowsWhenRemoveReturnsFalse(): void
    {
        $path = "{$this->cwd}/package.json";

        $this->assetFallback->save();

        file_put_contents($path, '{}');

        $this->io
            ->expects(self::once())
            ->method('write');
        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->with('package.json')
            ->willReturn(false);

        try {
            $this->assetFallback->restore();
            self::fail(
                'Expected restore to report the failed removal.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::FALLBACK_ASSET_REMOVE_FAILED->getMessage('package.json'),
                $exception->getMessage(),
                'Message must name the manifest that could not be removed.',
            );
            self::assertNull(
                $exception->getPrevious(),
                'Previous exception must be null when removal fails.',
            );
        }

        self::assertFileExists(
            $path,
            'File must exist after failed restore.',
        );
    }

    public function testRestoreThrowsWhenWriteFails(): void
    {
        $content = '{}';
        $path = "{$this->cwd}/package.json";

        file_put_contents($path, $content);

        $this->io
            ->expects(self::once())
            ->method('write');
        $this->fs
            ->expects(self::never())
            ->method('remove');

        $this->assetFallback->save();

        file_put_contents($path, '{"current":true}');

        MockerState::addCondition(
            'Foxy\\Fallback',
            'file_put_contents',
            ['package.json', $content, 0, null],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::FALLBACK_ASSET_WRITE_FAILED->getMessage('package.json'),
        );

        try {
            $this->assetFallback->restore();
        } catch (RuntimeException $exception) {
            self::assertSame(
                '{"current":true}',
                file_get_contents($path),
                'File content must remain unchanged after failed write.',
            );

            throw $exception;
        }
    }

    public function testRestoreUsesLatestSnapshot(): void
    {
        $path = "{$this->cwd}/package.json";

        file_put_contents($path, '{"original":true}');

        $this->assetFallback->save();
        $this->sfs->remove($path);
        $this->assetFallback->save();

        file_put_contents($path, '{"created":true}');

        $this->io
            ->expects(self::once())
            ->method('write');
        $this->fs
            ->expects(self::once())
            ->method('remove')
            ->with('package.json')
            ->willReturnCallback(
                function (string $file): bool {
                    $this->sfs->remove($file);

                    return true;
                }
            );

        $this->assetFallback->restore();

        self::assertFileDoesNotExist(
            $path,
            'File must not exist after restore.',
        );
    }

    public function testRestoreWithDisableOption(): void
    {
        $config = new Config(['fallback-asset' => false]);
        $assetFallback = new AssetFallback($this->io, $config, 'package.json', $this->fs);

        $this->io
            ->expects(self::never())
            ->method('write');
        $this->fs
            ->expects(self::never())
            ->method('remove');

        self::assertSame(
            $assetFallback,
            $assetFallback->save(),
            'Save must return the same AssetFallback instance.',
        );

        file_put_contents("{$this->cwd}/package.json", '{"current":true}');

        $assetFallback->restore();

        self::assertFileExists(
            "{$this->cwd}/package.json",
            'File must exist after restore.',
        );
    }

    public function testRestoreWrapsWriteException(): void
    {
        $content = '{}';
        $path = "{$this->cwd}/package.json";

        $failure = new \RuntimeException('Write failed.');

        file_put_contents($path, $content);

        $this->assetFallback->save();

        file_put_contents($path, '{"current":true}');

        $this->io
            ->expects(self::once())
            ->method('write');

        MockerState::addCondition(
            'Foxy\\Fallback',
            'file_put_contents',
            ['package.json', $content, 0, null],
            static fn(): never => throw $failure,
        );

        try {
            $this->assetFallback->restore();

            self::fail(
                'Expected the write exception to be wrapped.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::FALLBACK_ASSET_WRITE_FAILED->getMessage('package.json'),
                $exception->getMessage(),
                'Message must name the manifest that could not be written.',
            );
            self::assertSame(
                0,
                $exception->getCode(),
                'Exception code must be zero.',
            );
            self::assertSame(
                $failure,
                $exception->getPrevious(),
                'Previous exception must be the original failure.',
            );
            self::assertSame(
                '{"current":true}',
                file_get_contents($path),
                'File content must match the expected snapshot.',
            );
        }
    }

    #[DataProviderExternal(AssetFallbackProvider::class, 'snapshotScenarios')]
    public function testSave(bool $withPackageFile): void
    {
        if ($withPackageFile) {
            file_put_contents($this->cwd . '/package.json', '{}');
        }

        self::assertInstanceOf(
            AssetFallback::class,
            $this->assetFallback->save(),
            'Returned instance must be of type AssetFallback.',
        );
    }

    public function testSaveRejectsPreExistingNonFileManifestPath(): void
    {
        $path = "{$this->cwd}/package.json";
        $sentinel = "{$path}/keep.txt";

        $this->sfs->mkdir($path);

        file_put_contents($sentinel, 'keep');

        $this->io
            ->expects(self::never())
            ->method('write');
        $this->fs
            ->expects(self::never())
            ->method('remove');

        try {
            $this->assetFallback->save();

            self::fail(
                'Expected save to reject a non-file manifest path.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::FALLBACK_ASSET_PATH_NOT_FILE->getMessage('package.json'),
                $exception->getMessage(),
                'Message must name the non-regular manifest path.',
            );
        }

        $this->assetFallback->restore();

        self::assertFileExists(
            $sentinel,
            'Sentinel file must still exist after restore.',
        );
    }

    public function testSaveThrowsWhenFileCannotBeRead(): void
    {
        $path = "{$this->cwd}/package.json";

        file_put_contents($path, '{}');

        self::assertFileExists(
            $path,
            'Manifest file must exist before attempting to read it.',
        );

        MockerState::addCondition(
            'Foxy\\Fallback',
            'file_get_contents',
            ['package.json', false, null, 0, null],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::FALLBACK_ASSET_READ_FAILED->getMessage('package.json'),
        );

        $this->assetFallback->save();
    }

    public function testSaveWithDisabledOptionIgnoresInvalidManifestPath(): void
    {
        $path = "{$this->cwd}/package.json";

        $this->sfs->mkdir($path);

        $config = new Config(['fallback-asset' => false]);
        $assetFallback = new AssetFallback($this->io, $config, 'package.json', $this->fs);

        self::assertSame(
            $assetFallback,
            $assetFallback->save(),
            'AssetFallback::save() must return the instance when the fallback option is disabled.',
        );
        self::assertDirectoryExists(
            $path,
            'Manifest path must still exist as a directory when the fallback option is disabled.',
        );
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
