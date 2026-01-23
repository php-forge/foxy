<?php

declare(strict_types=1);

namespace Foxy\Tests\Json;

use Foxy\Json\JsonFormatter;
use JsonException;
use PHPForge\Support\LineEndingNormalizer;
use PHPUnit\Framework\TestCase;

final class JsonFormatterTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testFormat(): void
    {
        $expected = <<<JSON
        {
          "name": "test",
          "contributors": [],
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

        $content = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertSame(
            LineEndingNormalizer::normalize($expected),
            LineEndingNormalizer::normalize(JsonFormatter::format($content, ['contributors'], 2)),
        );
    }

    /**
     * @throws JsonException
     */
    public function testFormatWithEmptyContent(): void
    {
        self::assertEmpty(
            JsonFormatter::format('', [], 2),
        );
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

        self::assertSame(
            $expected,
            JsonFormatter::getArrayKeys($content),
        );
    }

    public function testGetArrayKeysWithoutSpacesBeforeArray(): void
    {
        $content = '{"name":"test","workspaces":[]}';
        $expected = ['workspaces'];

        self::assertSame(
            $expected,
            JsonFormatter::getArrayKeys($content),
        );
    }

    public function testGetIndent(): void
    {
        $content = <<<JSON
        {
          "name": "test",
          "dependencies": {}
        }
        JSON;

        self::assertSame(
            2,
            JsonFormatter::getIndent($content),
        );
    }

    /**
     * @throws JsonException
     */
    public function testUnescapeSlashes(): void
    {
        $data = ['url' => 'https:\/\/example.com'];

        $content = json_encode($data, JSON_THROW_ON_ERROR);

        $expected = <<<JSON
        {
            "url": "https://example.com"
        }
        JSON;

        self::assertSame(
            LineEndingNormalizer::normalize($expected),
            LineEndingNormalizer::normalize(JsonFormatter::format($content, [], 4)),
        );
    }

    /**
     * @throws JsonException
     */
    public function testUnescapeUnicode(): void
    {
        $data = ['name' => '\u0048\u0065\u006c\u006c\u006f'];

        $content = json_encode($data, JSON_THROW_ON_ERROR);

        $expected = <<<JSON
        {
          "name": "Hello"
        }
        JSON;

        self::assertSame(
            LineEndingNormalizer::normalize($expected),
            LineEndingNormalizer::normalize(JsonFormatter::format($content, [], 2)),
        );
    }
}
