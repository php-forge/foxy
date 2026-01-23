<?php

declare(strict_types=1);

namespace Foxy\Tests\Fallback;

use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryManager;
use Composer\Util\Filesystem;
use Exception;
use Foxy\Config\Config;
use Foxy\Fallback\ComposerFallback;
use Foxy\Util\LockerUtil;
use JsonException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;

use function chdir;
use function count;
use function file_put_contents;

use const DIRECTORY_SEPARATOR;

final class ComposerFallbackTest extends TestCase
{
    private Composer|MockObject|null $composer = null;
    private ComposerFallback|null $composerFallback = null;
    private Config|null $config = null;
    private string|null $cwd = '';
    private Filesystem|MockObject|null $fs = null;
    private InputInterface|MockObject|null $input = null;
    private Installer|MockObject|null $installer = null;
    private IOInterface|MockObject|null $io = null;
    private string|null $oldCwd = '';
    private \Symfony\Component\Filesystem\Filesystem|null $sfs = null;

    public static function getRestoreData(): array
    {
        return [[[]], [[['name' => 'foo/bar', 'version' => '1.0.0.0']]]];
    }

    public static function getSaveData(): array
    {
        return [[true], [false]];
    }

    /**
     * @dataProvider getRestoreData
     *
     * @throws Exception|JsonException
     */
    public function testRestore(array $packages): void
    {
        $composerFile = 'composer.json';
        $composerContent = '{}';
        $lockFile = 'composer.lock';
        $vendorDir = $this->cwd . '/vendor/';

        file_put_contents($this->cwd . '/' . $composerFile, $composerContent);
        file_put_contents(
            $this->cwd . '/' . $lockFile,
            json_encode(
                [
                    'content-hash' => 'HASH_VALUE',
                    'packages' => $packages,
                    'packages-dev' => [],
                    'prefer-stable' => true,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );

        $this->input
            ->expects(self::any())
            ->method('getOption')
            ->willReturnCallback(fn($option): ?bool => 'verbose' === $option ? false : null);

        $ed = $this->createMock(EventDispatcher::class);

        $this->composer->expects(self::any())->method('getEventDispatcher')->willReturn($ed);

        $rm = $this->createMock(RepositoryManager::class);

        $this->composer->expects(self::any())->method('getRepositoryManager')->willReturn($rm);

        $im = $this->createMock(InstallationManager::class);

        $this->composer->expects(self::any())->method('getInstallationManager')->willReturn($im);
        $this->io->expects(self::once())->method('write');

        $locker = LockerUtil::getLocker($this->io, $im, $composerFile);

        $this->composer->expects(self::atLeastOnce())->method('getLocker')->willReturn($locker);

        $config = $this->getMockBuilder(\Composer\Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();

        $this->composer->expects(self::atLeastOnce())->method('getConfig')->willReturn($config);

        $config
            ->expects(self::atLeastOnce())
            ->method('get')
            ->willReturnCallback(fn($key, $default = null) => 'vendor-dir' === $key ? $vendorDir : $default);

        if (0 === count($packages)) {
            $this->fs->expects(self::once())->method('remove')->with($vendorDir);
        } else {
            $this->fs->expects(self::never())->method('remove');
            $this->installer->expects(self::once())->method('run');
        }

        $this->composerFallback->save();
        $this->composerFallback->restore();
    }

    /**
     * @throws Exception
     */
    public function testRestoreWithDisableOption(): void
    {
        $config = new Config(['fallback-composer' => false]);
        $composerFallback = new ComposerFallback($this->composer, $this->io, $config, $this->input);

        $this->io->expects(self::never())->method('write');

        $composerFallback->restore();
    }

    /**
     * @dataProvider getSaveData
     */
    public function testSave(bool $withLockFile): void
    {
        $rm = $this->createMock(RepositoryManager::class);

        $this->composer->expects(self::any())->method('getRepositoryManager')->willReturn($rm);

        $im = $this->createMock(InstallationManager::class);

        $this->composer->expects(self::any())->method('getInstallationManager')->willReturn($im);

        file_put_contents($this->cwd . '/composer.json', '{}');

        if ($withLockFile) {
            file_put_contents($this->cwd . '/composer.lock', json_encode(['content-hash' => 'HASH_VALUE']));
        }

        self::assertInstanceOf(ComposerFallback::class, $this->composerFallback->save());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_composer_fallback_test_', true);
        $this->config = new Config(['fallback-composer' => true]);
        $this->composer = $this->createMock(Composer::class);
        $this->io = $this->createMock(IOInterface::class);
        $this->input = $this->createMock(InputInterface::class);
        $this->fs = $this->createMock(Filesystem::class);
        /** @var Installer|MockObject $this */
        $this->installer = $this
            ->getMockBuilder(Installer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['run'])
            ->getMock();
        $this->sfs = new \Symfony\Component\Filesystem\Filesystem();
        $this->sfs->mkdir($this->cwd);

        chdir($this->cwd);

        $this->composerFallback = new ComposerFallback(
            $this->composer,
            $this->io,
            $this->config,
            $this->input,
            $this->fs,
            $this->installer,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);

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
}
