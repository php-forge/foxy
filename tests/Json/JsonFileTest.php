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

namespace Foxy\Tests\Json;

use Foxy\Json\JsonFile;
use PHPForge\Support\Assert;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests for json file.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class JsonFileTest extends \PHPUnit\Framework\TestCase
{
    private Filesystem|null $sfs = null;
    private string|null $oldCwd = '';
    private string|null $cwd = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . uniqid('foxy_asset_json_file_test_', true);
        $this->sfs = new Filesystem();
        $this->sfs->mkdir($this->cwd);

        \chdir($this->cwd);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        \chdir($this->oldCwd);

        $this->sfs->remove($this->cwd);
        $this->sfs = null;
        $this->oldCwd = null;
        $this->cwd = null;
    }

    public function testGetArrayKeysWithoutFile(): void
    {
        $filename = './package.json';
        $jsonFile = new JsonFile($filename);

        $this->assertSame([], $jsonFile->getArrayKeys());
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
        $this->assertFileExists($filename);

        $jsonFile = new JsonFile($filename);

        $this->assertSame($expected, $jsonFile->getArrayKeys());
    }

    public function testGetIndentWithoutFile(): void
    {
        $filename = './package.json';
        $jsonFile = new JsonFile($filename);

        $this->assertSame(4, $jsonFile->getIndent());
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
        $this->assertFileExists($filename);

        $jsonFile = new JsonFile($filename);

        $this->assertSame(2, $jsonFile->getIndent());
    }

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

        $this->assertFileExists($filename);
        $content = file_get_contents($filename);

        Assert::equalsWithoutLE($expected, $content);
    }

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
        $this->assertFileExists($filename);

        $jsonFile = new JsonFile($filename);
        $data = (array) $jsonFile->read();
        $data['private'] = true;
        $jsonFile->write($data);

        $this->assertFileExists($filename);
        $content = file_get_contents($filename);

        $this->assertSame($expected, $content);
    }
}
