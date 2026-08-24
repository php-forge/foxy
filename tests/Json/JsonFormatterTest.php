<?php

declare(strict_types=1);

namespace Foxy\Tests\Json;

use Foxy\Json\JsonFormatter;
use JsonException;
use PHPForge\Support\LineEndingNormalizer;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function rtrim;

final class JsonFormatterTest extends TestCase
{
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
        $content = self::fixture('package-two-space.json');
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
        $content = self::fixture('package-two-space.json');

        self::assertSame(
            2,
            JsonFormatter::getIndent($content),
        );
    }

    public function testGetIndentIgnoresSurroundingWhitespace(): void
    {
        $content = "\n  " . self::fixture('name-two-space.json');

        self::assertSame(2, JsonFormatter::getIndent($content));
    }

    public function testGetMapKeys(): void
    {
        self::assertSame(
            ['dependencies', 'metadata'],
            JsonFormatter::getMapKeys('{"dependencies":{},"metadata": { }}'),
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
            LineEndingNormalizer::normalize(
                self::fixtureWithoutFinalNewline('literal-slashes-four-space.json'),
            ),
            LineEndingNormalizer::normalize(JsonFormatter::format($content, [], 4)),
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
            LineEndingNormalizer::normalize(
                self::fixtureWithoutFinalNewline('literal-unicode-two-space.json'),
            ),
            LineEndingNormalizer::normalize(JsonFormatter::format($content, [], 2)),
        );
    }

    /**
     * @throws JsonException
     */
    public function testPreservesRootObjectAndSpacesInsideStrings(): void
    {
        self::assertSame('{}', JsonFormatter::format('{}', [], 2));
        self::assertStringContainsString(
            '"value": "left    right"',
            JsonFormatter::format('{"value":"left    right"}', [], 2),
        );
    }

    private static function fixture(string $filename): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/Json/' . $filename);
    }

    private static function fixtureWithoutFinalNewline(string $filename): string
    {
        return rtrim(self::fixture($filename), "\r\n");
    }
}
