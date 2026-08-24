<?php

declare(strict_types=1);

namespace Foxy\Json;

use Composer\Pcre\Preg;
use JsonException;

use function in_array;
use function intdiv;
use function json_decode;
use function json_encode;
use function preg_match;
use function preg_match_all;
use function str_repeat;
use function str_replace;
use function strlen;
use function trim;

final class JsonFormatter
{
    public const string ARRAY_KEYS_REGEX = '/["\']([\w\-.]+)["\']\s*:\s*\[\s*]/';
    public const int DEFAULT_INDENT = 4;
    public const string INDENT_REGEX = '/^[{\[][\r\n]( +)["\']/';
    public const string MAP_KEYS_REGEX = '/["\']([\w\-.]+)["\']\s*:\s*\{\s*}/';

    /**
     * Format the data in JSON.
     *
     * @param string $json The original JSON.
     * @param string[] $arrayKeys The list of keys to be retained with an array representation if they are empty.
     * @param int $indent The space count for indent.
     * @param bool $formatJson Check if the JSON must be formatted.
     *
     * @throws JsonException if the JSON cannot be decoded or encoded.
     */
    public static function format(
        string $json,
        array $arrayKeys = [],
        int $indent = self::DEFAULT_INDENT,
        bool $formatJson = true,
    ): string {
        if ($formatJson) {
            $json = self::formatInternal($json);
        }

        if (self::DEFAULT_INDENT !== $indent) {
            $json = Preg::replaceCallback(
                '/^( {4})+/m',
                static fn(array $match): string => str_repeat(
                    ' ',
                    intdiv(strlen($match[0]), self::DEFAULT_INDENT) * $indent,
                ),
                $json,
            );
        }

        return self::replaceArrayByMap($json, $arrayKeys);
    }

    /**
     * Get the list of keys to be retained with an array representation if they are empty.
     *
     * @param string $content The content.
     *
     * @psalm-return string[] The list of keys to be retained with an array representation if they are empty.
     */
    public static function getArrayKeys(string $content): array
    {
        preg_match_all(self::ARRAY_KEYS_REGEX, $content, $matches);

        return $matches[1];
    }

    /**
     * Get the indent of file.
     *
     * @param string $content The content
     */
    public static function getIndent(string $content): int
    {
        if (1 !== preg_match(self::INDENT_REGEX, trim($content), $matches)) {
            return self::DEFAULT_INDENT;
        }

        return strlen($matches[1]);
    }

    /**
     * Get the keys that were represented as empty JSON objects.
     *
     * @psalm-return string[]
     */
    public static function getMapKeys(string $content): array
    {
        preg_match_all(self::MAP_KEYS_REGEX, $content, $matches);

        return $matches[1];
    }

    /**
     * Format the data in JSON.
     *
     * @throws JsonException
     */
    private static function formatInternal(string $json): string
    {
        if ($json === '') {
            return $json;
        }

        $data = json_decode($json, false, flags: JSON_THROW_ON_ERROR);

        return json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Replace the empty array by empty map.
     *
     * @param string $json The original JSON.
     * @param array $arrayKeys The list of keys to be retained with an array representation if they are empty.
     *
     * @psalm-param string[] $arrayKeys The list of keys to be retained with an array representation if they are empty.
     */
    private static function replaceArrayByMap(string $json, array $arrayKeys): string
    {
        preg_match_all(self::ARRAY_KEYS_REGEX, $json, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (!in_array($match[1], $arrayKeys, true)) {
                $replace = Preg::replace('/\[\s*]/', '{}', $match[0]);
                $json = str_replace($match[0], $replace, $json);
            }
        }

        return $json;
    }
}
