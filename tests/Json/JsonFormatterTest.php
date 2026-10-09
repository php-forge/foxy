<?php

declare(strict_types=1);

namespace Foxy\Tests\Json;

use Foxy\Json\JsonFormatter;
use Foxy\Tests\Support\JsonFixture;
use JsonException;
use PHPForge\Support\LineEndingNormalizer;
use PHPUnit\Framework\TestCase;

final class JsonFormatterTest extends TestCase
{
    use JsonFixture;

    /**
     * @throws JsonException
     */
    public function testFormat(): void
    {
        $data = [
            'name' => 'test',
            'contributors' => [],
            'dependencies' => ['@foo/bar' => '^1.0.0'], 'devDependencies' => [],
        ];

        $content = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertSame(
            LineEndingNormalizer::normalize(self::fixtureWithoutFinalNewline('formatter-output-two-space.json')),
            LineEndingNormalizer::normalize(JsonFormatter::format($content, ['contributors'], 2)),
            'The formatted JSON content should match the expected output.',
        );
    }

    /**
     * @throws JsonException
     */
    public function testFormatWithEmptyContent(): void
    {
        self::assertEmpty(
            JsonFormatter::format('', [], 2),
            'Formatting empty content should result in an empty string.',
        );
    }

    public function testGetArrayKeys(): void
    {
        $content = self::fixture('package-two-space.json');
        $expected = ['contributors'];

        self::assertSame(
            $expected,
            JsonFormatter::getArrayKeys($content),
            'The array keys extracted from the JSON content should match the expected keys.',
        );
    }

    public function testGetArrayKeysWithoutSpacesBeforeArray(): void
    {
        $content = '{"name":"test","workspaces":[]}';
        $expected = ['workspaces'];

        self::assertSame(
            $expected,
            JsonFormatter::getArrayKeys($content),
            'The array keys extracted from the JSON content should match the expected keys.',
        );
    }

    public function testGetIndent(): void
    {
        $content = self::fixture('package-two-space.json');

        self::assertSame(
            2,
            JsonFormatter::getIndent($content),
            'The indent extracted from the JSON content should match the expected indent.',
        );
    }

    public function testGetIndentIgnoresSurroundingWhitespace(): void
    {
        $content = "\n  " . self::fixture('name-two-space.json');

        self::assertSame(
            2,
            JsonFormatter::getIndent($content),
            'The indent extracted from the JSON content should match the expected indent.',
        );
    }

    public function testGetMapKeys(): void
    {
        self::assertSame(
            ['dependencies', 'metadata'],
            JsonFormatter::getMapKeys('{"dependencies":{},"metadata": { }}'),
            'The map keys extracted from the JSON content should match the expected keys.',
        );
    }

    /**
     * @throws JsonException
     */
    public function testPreservesLiteralEscapedSlashes(): void
    {
        $data = ['url' => 'https:\/\/example.com'];

        $content = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertSame(
            LineEndingNormalizer::normalize(self::fixtureWithoutFinalNewline('literal-slashes-four-space.json')),
            LineEndingNormalizer::normalize(JsonFormatter::format($content, [], 4)),
            'The formatted JSON content should preserve literal escaped slashes.',
        );
    }

    /**
     * @throws JsonException
     */
    public function testPreservesLiteralUnicodeEscapeSequences(): void
    {
        $data = ['name' => '\u0048\u0065\u006c\u006c\u006f'];

        $content = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertSame(
            LineEndingNormalizer::normalize(self::fixtureWithoutFinalNewline('literal-unicode-two-space.json')),
            LineEndingNormalizer::normalize(JsonFormatter::format($content, [], 2)),
            'The formatted JSON content should preserve literal Unicode escape sequences.',
        );
    }

    /**
     * @throws JsonException
     */
    public function testPreservesRootObjectAndSpacesInsideStrings(): void
    {
        self::assertSame(
            '{}',
            JsonFormatter::format('{}', [], 2),
            'The formatted JSON content should preserve the root object and spaces inside strings.',
        );
        self::assertStringContainsString(
            '"value": "left    right"',
            JsonFormatter::format('{"value":"left    right"}', [], 2),
            'The formatted JSON content should preserve spaces inside strings.',
        );
    }
}
