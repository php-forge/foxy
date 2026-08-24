<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\Json\JsonFile;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem as ComposerFilesystem;
use Exception;
use Foxy\Asset\AssetPackage;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Seld\JsonLint\ParsingException;
use Symfony\Component\Filesystem\Filesystem;
use Xepozz\InternalMocker\MockerState;

use function chdir;
use function file_put_contents;
use function getcwd;

use const DIRECTORY_SEPARATOR;

final class AssetPackageTest extends TestCase
{
    protected string|null $cwd = '';
    protected JsonFile|MockObject|null $jsonFile = null;
    protected string|null $oldCwd = '';
    protected MockObject|RootPackageInterface|null $rootPackage = null;
    protected Filesystem|null $sfs = null;

    public static function getDataRequiredKeys(): array
    {
        return [
            [
                [
                    'name' => '@foo/bar',
                    'license' => 'MIT',
                ],
                [
                    'name' => '@foo/bar',
                    'license' => 'MIT',
                ],
                'proprietary',
            ],
            [
                [
                    'name' => '@foo/bar',
                    'license' => 'MIT',
                ],
                [
                    'name' => '@foo/bar',
                ],
                'MIT',
            ],
            [
                [
                    'name' => '@foo/bar',
                    'private' => true,
                ],
                [
                    'name' => '@foo/bar',
                ],
                'proprietary',
            ],
        ];
    }

    /**
     * @throws JsonException|ParsingException
     */
    public function testAddNewDependencies(): void
    {
        $expected = [
            'dependencies' => [
                '@bar/foo' => '^1.0.0',
                '@composer-asset/baz--bar' => 'file:./path/baz/bar',
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@composer-asset/new--dependency' => 'file:./path/new/dependency',
            ],
            'devDependencies' => [
                '@dev/bar' => '^1.0.0',
                '@dev/foo' => '^1.0.0',
            ],
        ];
        $expectedExisting = [
            '@composer-asset/foo--bar',
            '@composer-asset/baz--bar',
        ];
        $package = [
            'dependencies' => [
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@bar/foo' => '^1.0.0',
                '@composer-asset/baz--bar' => 'file:./path/baz/bar',
            ],
            'devDependencies' => [
                '@dev/foo' => '^1.0.0',
                '@dev/bar' => '^1.0.0',
            ],
        ];
        $dependencies = [
            '@composer-asset/foo--bar' => 'path/foo/bar/package.json',
            '@composer-asset/baz--bar' => 'path/baz/bar/package.json',
            '@composer-asset/new--dependency' => 'path/new/dependency/package.json',
        ];

        $this->addPackageFile($package);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);
        $existing = $assetPackage->addNewDependencies($dependencies);

        self::assertSame(
            $expected,
            $assetPackage->getPackage(),
        );
        self::assertSame(
            $expectedExisting,
            $existing,
        );
    }

    /**
     * @throws ParsingException
     */
    public function testAddNewDependenciesPreservesFileUriFromEventListener(): void
    {
        $this->jsonFile->expects(self::once())->method('exists')->willReturn(false);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);
        $assetPackage->addNewDependencies(
            ['@composer-asset/foo--bar' => 'file:../custom/foo/bar'],
        );

        self::assertSame(
            'file:../custom/foo/bar',
            $assetPackage->getPackage()['dependencies']['@composer-asset/foo--bar'],
        );
    }

    /**
     * @throws JsonException|ParsingException
     */
    public function testAddNewDependenciesUpdatesExistingManagedPath(): void
    {
        $this->addPackageFile(
            ['dependencies' => ['@composer-asset/foo--bar' => 'file:./stale/path']],
        );

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);
        $existing = $assetPackage->addNewDependencies(
            ['@composer-asset/foo--bar' => $this->cwd . '/fresh/path/package.json'],
        );

        self::assertSame(['@composer-asset/foo--bar'], $existing);
        self::assertSame(
            'file:./fresh/path',
            $assetPackage->getPackage()['dependencies']['@composer-asset/foo--bar'],
        );
    }

    public function testDependencyPathUsesInjectedFilesystemForPortableRelativePath(): void
    {
        $path = 'asset/foo/package.json';
        $consumerPath = '/consumer/package.json';
        $relativePath = '..\\asset\\foo';
        $fs = $this->createMock(ComposerFilesystem::class);

        $this->jsonFile->expects(self::once())->method('exists')->willReturn(false);
        $this->jsonFile->expects(self::once())->method('getPath')->willReturn($consumerPath);
        $fs
            ->expects(self::exactly(3))
            ->method('isAbsolutePath')
            ->willReturnMap(
                [
                    [$path, false],
                    [$consumerPath, true],
                    [$relativePath, false],
                ],
            );
        $fs
            ->expects(self::once())
            ->method('findShortestPath')
            ->with('/consumer', DIRECTORY_SEPARATOR . 'asset/foo', true, true)
            ->willReturn($relativePath);

        MockerState::addCondition('Foxy\\Asset', 'getcwd', [], DIRECTORY_SEPARATOR);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile, $fs);
        $assetPackage->addNewDependencies(['@composer-asset/foo--bar' => $path]);

        self::assertSame(
            'file:../asset/foo',
            $assetPackage->getPackage()['dependencies']['@composer-asset/foo--bar'],
        );
    }

    /**
     * @throws ParsingException
     * @throws JsonException
     */
    public function testGetInstalledDependencies(): void
    {
        $expected = [
            '@composer-asset/foo--bar' => 'file:./path/foo/bar',
            '@composer-asset/baz--bar' => 'file:./path/baz/bar',
        ];
        $package = [
            'dependencies' => [
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@bar/foo' => '^1.0.0',
                '@composer-asset/baz--bar' => 'file:./path/baz/bar',
            ],
        ];

        $this->addPackageFile($package);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);

        self::assertSame(
            $expected,
            $assetPackage->getInstalledDependencies(),
        );
    }

    /**
     * @throws ParsingException
     * @throws JsonException
     */
    public function testGetPackageWithExistingFile(): void
    {
        $package = ['name' => '@foo/bar'];

        $contentString = json_encode($package, JSON_THROW_ON_ERROR);

        $this->addPackageFile($package, $contentString);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);

        self::assertSame(
            $package,
            $assetPackage->getPackage(),
        );
    }

    /**
     * @throws JsonException|ParsingException
     */
    #[DataProvider('getDataRequiredKeys')]
    public function testInjectionOfRequiredKeys(array $expected, array $package, string $license): void
    {
        $this->addPackageFile($package);

        $this->rootPackage = $this->createMock(RootPackageInterface::class);

        $this->rootPackage->expects(self::any())->method('getLicense')->willReturn([$license]);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);

        self::assertSame(
            $expected,
            $assetPackage->getPackage(),
        );
    }

    /**
     * @throws ParsingException
     * @throws JsonException
     */
    public function testRemoveUnusedDependencies(): void
    {
        $expected = [
            'dependencies' => [
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@bar/foo' => '^1.0.0',
            ],
        ];

        $package = [
            'dependencies' => [
                '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                '@bar/foo' => '^1.0.0',
                '@composer-asset/baz--bar' => 'file:./path/baz/bar',
            ],
        ];
        $dependencies = ['@composer-asset/foo--bar' => 'file:./path/foo/bar'];

        $this->addPackageFile($package);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);

        $assetPackage->removeUnusedDependencies($dependencies);

        self::assertSame(
            $expected,
            $assetPackage->getPackage(),
        );
    }

    /**
     * @throws Exception|ParsingException
     */
    public function testWrite(): void
    {
        $package = ['name' => '@foo/bar'];

        $this->jsonFile->expects(self::once())->method('exists')->willReturn(false);
        $this->jsonFile->expects(self::once())->method('write')->with($package);

        $assetPackage = new AssetPackage($this->rootPackage, $this->jsonFile);

        $assetPackage->setPackage($package);
        $assetPackage->write();
    }

    /**
     * Add the package in file.
     *
     * @param array $package The package.
     * @param string|null $contentString The string content of package.
     *
     * @throws JsonException
     */
    protected function addPackageFile(array $package, string|null $contentString = null): void
    {
        $filename = $this->cwd . '/package.json';
        $contentString ??= json_encode($package, JSON_THROW_ON_ERROR);

        $this->jsonFile->expects(self::any())->method('exists')->willReturn(true);
        $this->jsonFile->expects(self::any())->method('getPath')->willReturn($filename);
        $this->jsonFile->expects(self::any())->method('read')->willReturn($package);

        file_put_contents($filename, $contentString);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->cwd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_asset_package_test_', true);
        $this->oldCwd = getcwd();

        $this->sfs = new Filesystem();

        $this->rootPackage = $this->createMock(RootPackageInterface::class);
        $this->jsonFile = $this
            ->getMockBuilder(JsonFile::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['exists', 'getPath', 'read', 'write'])
            ->getMock()
        ;
        $this->rootPackage->expects(self::any())->method('getLicense')->willReturn([]);

        $this->sfs->mkdir($this->cwd);
        chdir($this->cwd);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);
        $this->sfs->remove($this->cwd);
        $this->jsonFile = null;
        $this->rootPackage = null;
        $this->sfs = null;
        $this->cwd = null;
        $this->oldCwd = null;
    }
}
