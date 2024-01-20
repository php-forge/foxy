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
        $expected = <<<JSON
        {
          "name": "test",
          "contributors": {},
          "dependencies": {
            "@foo/bar": "^1.0.0"
          },
          "devDependencies": {}
        }
        JSON;

        $data = [
            'name' => 'test',
            'contributors' => [],
            'dependencies' => ['@foo/bar' => '^1.0.0'], 'devDependencies' => [],
        ];
        $content = json_encode($data);

        Assert::equalsWithoutLE($expected, JsonFormatter::format($content, [], 2));
    }

    public function testFormatWithEmptyContent(): void
    {
        $this->assertEmpty(JsonFormatter::format('', [], 2));
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
        $expected = ['contributors'];

        $this->assertSame($expected, JsonFormatter::getArrayKeys($content));
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
        $data = ['name' => '\u0048\u0065\u006c\u006c\u006f'];
        $content = json_encode($data);

        $expected = <<<JSON
        {
          "name": "Hello"
        }
        JSON;

        Assert::equalsWithoutLE($expected, JsonFormatter::format($content, [], 2, true));
    }

    public function testUnescapeSlashes(): void
    {
        $data = ['url' => 'https:\/\/example.com'];
        $content = json_encode($data);

        $expected = <<<JSON
        {
            "url": "https://example.com"
        }
        JSON;

        Assert::equalsWithoutLE($expected, JsonFormatter::format($content, [], 4, true));
    }
}
