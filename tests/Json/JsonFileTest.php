<?php

declare(strict_types=1);

namespace Foxy\Tests\Json;

use Exception;
use Foxy\Exception\RuntimeException;
use Foxy\Json\JsonFile;
use PHPForge\Support\LineEndingNormalizer;
use PHPUnit\Framework\TestCase;
use Seld\JsonLint\ParsingException;
use Symfony\Component\Filesystem\Filesystem;
use Xepozz\InternalMocker\MockerState;

use function chdir;
use function file_get_contents;

use const DIRECTORY_SEPARATOR;

final class JsonFileTest extends TestCase
{
    private string|null $cwd = '';
    private string|null $oldCwd = '';
    private Filesystem|null $sfs = null;

    public function testGetArrayKeysThrowsWhenFileCannotBeRead(): void
    {
        $filename = './package.json';

        file_put_contents($filename, '{}');

        self::assertFileExists($filename);

        MockerState::addCondition('Foxy\\Json', 'file_get_contents', [$filename, false, null, 0, null], false);

        $jsonFile = new JsonFile($filename);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unable to read json file ".+package\.json"\./');

        $jsonFile->getArrayKeys();
    }

    public function testGetArrayKeysWithExistingFile(): void
    {
        $expected = ['contributors'];
        $content = <<<JSON
        {
          "name": "test",
          "contributors": [],
          "dependencies": {}
        }
        JSON;

        $filename = './package.json';

        file_put_contents($filename, $content);

        self::assertFileExists($filename);

        $jsonFile = new JsonFile($filename);

        self::assertSame($expected, $jsonFile->getArrayKeys());
    }

    public function testGetArrayKeysWithoutFile(): void
    {
        $filename = './package.json';

        $jsonFile = new JsonFile($filename);

        self::assertSame([], $jsonFile->getArrayKeys());
    }

    public function testGetIndentWithExistingFile(): void
    {
        $content = <<<JSON
        {
          "name": "test"
        }
        JSON;

        $filename = './package.json';

        file_put_contents($filename, $content);

        self::assertFileExists($filename);

        $jsonFile = new JsonFile($filename);

        self::assertSame(2, $jsonFile->getIndent());
    }

    public function testGetIndentWithoutFile(): void
    {
        $filename = './package.json';
        $jsonFile = new JsonFile($filename);

        self::assertSame(4, $jsonFile->getIndent());
    }

    /**
     * @throws Exception|ParsingException
     */
    public function testWriteForcesFourSpacesIndentWithExistingTwoSpaceFile(): void
    {
        $expected = <<<JSON
        {
            "name": "test",
            "private": true
        }

        JSON;
        $content = <<<'JSON'
        {
          "name": "test"
        }

        JSON;

        $filename = './package.json';

        file_put_contents($filename, $content);

        self::assertFileExists($filename);

        $jsonFile = new JsonFile($filename);

        $data = $jsonFile->read();

        $data['private'] = true;

        $jsonFile->write($data);

        self::assertFileExists($filename);

        $content = file_get_contents($filename);

        self::assertSame(
            LineEndingNormalizer::normalize($expected),
            LineEndingNormalizer::normalize($content),
        );
    }

    /**
     * @throws Exception|ParsingException
     */
    public function testWritePreservesNestedEmptyArraysWithoutSpaces(): void
    {
        $content = '{"name":"test","workspaces":[],"overrides":{"pkg":{"files":[]}},"dependencies":{}}';

        $filename = './package.json';

        file_put_contents($filename, $content);

        self::assertFileExists($filename);

        $jsonFile = new JsonFile($filename);

        $data = $jsonFile->read();

        $data['private'] = true;

        $jsonFile->write($data);

        self::assertFileExists($filename);

        $content = file_get_contents($filename);

        self::assertStringContainsString('"workspaces": []', $content);
        self::assertStringContainsString('"files": []', $content);
        self::assertStringContainsString('"dependencies": {}', $content);
        self::assertMatchesRegularExpression('/^ {4}"dependencies": \{\}/m', $content);
    }

    /**
     * @throws Exception|ParsingException
     */
    public function testWriteWithExistingFile(): void
    {
        $expected = <<<JSON
        {
            "name": "test",
            "contributors": [],
            "dependencies": {},
            "private": true
        }

        JSON;
        $content = <<<'JSON'
        {
          "name": "test",
          "contributors": [],
          "dependencies": {}
        }

        JSON;

        $filename = './package.json';

        file_put_contents($filename, $content);

        self::assertFileExists($filename);

        $jsonFile = new JsonFile($filename);

        $data =  $jsonFile->read();

        $data['private'] = true;

        $jsonFile->write($data);

        self::assertFileExists($filename);

        $content = file_get_contents($filename);

        self::assertSame(
            LineEndingNormalizer::normalize($expected),
            LineEndingNormalizer::normalize($content),
        );
    }

    /**
     * @throws Exception
     */
    public function testWriteWithoutFile(): void
    {
        $expected = <<<JSON
        {
            "name": "test"
        }

        JSON;

        $filename = './package.json';
        $data = ['name' => 'test'];

        $jsonFile = new JsonFile($filename);

        $jsonFile->write($data);

        self::assertFileExists($filename);

        $content = file_get_contents($filename);

        self::assertSame(
            LineEndingNormalizer::normalize($expected),
            LineEndingNormalizer::normalize($content),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_asset_json_file_test_', true);
        $this->sfs = new Filesystem();
        $this->sfs->mkdir($this->cwd);

        chdir($this->cwd);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->oldCwd);

        $this->sfs->remove($this->cwd);
        $this->sfs = null;
        $this->oldCwd = null;
        $this->cwd = null;
    }
}
