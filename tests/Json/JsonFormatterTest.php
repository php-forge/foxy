<?php

/*
 * This file is part of the Foxy package.
 *
 * (c) François Pluchino <francois.pluchino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Foxy\Tests\Json;

use Foxy\Json\JsonFormatter;
use PHPForge\Support\Assert;

/**
 * Tests for json formatter.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class JsonFormatterTest extends \PHPUnit\Framework\TestCase
{
    public function testFormat(): void
    {
        $expected = <<<'JSON'
        {
          "name": "test",
          "contributors": {},
          "dependencies": {
            "@foo/bar": "^1.0.0"
          },
          "devDependencies": {}
        }
        JSON;
        $data = array(
            'name' => 'test',
            'contributors' => array(),
            'dependencies' => array(
                '@foo/bar' => '^1.0.0',
            ),
            'devDependencies' => array(),
        );
        $content = json_encode($data);

        Assert::equalsWithoutLE($expected, JsonFormatter::format($content, array(), 2));
    }

    public function testGetArrayKeys(): void
    {
        $content = <<<JSON
        {
          "name": "test",
          "contributors": [],
          "dependencies": {}
        }
        JSON;
        $expected = array('contributors');

        static::assertSame($expected, JsonFormatter::getArrayKeys($content));
    }

    public function testGetIndent(): void
    {
        $content = <<<JSON
        {
          "name": "test",
          "dependencies": {}
        }
        JSON;

        $this->assertSame(2, JsonFormatter::getIndent($content));
    }

    public function testUnescapeUnicode(): void
    {
        $data = array(
            'name' => '\u0048\u0065\u006c\u006c\u006f', // Hello en unicode
        );
        $content = json_encode($data);

        $expected = <<<JSON
        {
          "name": "Hello"
        }
        JSON;

        Assert::equalsWithoutLE($expected, JsonFormatter::format($content, array(), 2, true));
    }

    public function testUnescapeSlashes(): void
    {
        $data = array(
            'url' => 'https:\/\/example.com', // URL con barras diagonales escapadas
        );
        $content = json_encode($data);

        $expected = <<<'JSON'
        {
            "url": "https://example.com"
        }
        JSON;

        Assert::equalsWithoutLE($expected, JsonFormatter::format($content, array(), 4, true));
    }
}
