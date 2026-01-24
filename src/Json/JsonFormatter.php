<?php

declare(strict_types=1);

namespace Foxy\Json;

use JsonException;

use function array_walk_recursive;
use function in_array;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_convert_encoding;
use function pack;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function str_repeat;
use function str_replace;
use function strlen;
use function trim;

final class JsonFormatter
{
    public const ARRAY_KEYS_REGEX = '/["\']([\w\-.]+)["\']\s*:\s*\[\s*]/';
    public const DEFAULT_INDENT = 4;
    public const INDENT_REGEX = '/^[{\[][\r\n]( +)["\']/';

    /**
     * Format the data in JSON.
     *
     * @param string $json The original JSON.
     * @param array $arrayKeys The list of keys to be retained with an array representation if they are empty.
     * @param int $indent The space count for indent.
     * @param bool $formatJson Check if the JSON must be formatted.
     *
     * @psalm-param string[] $arrayKeys The list of keys to be retained with an array representation if they are empty.
     *
     * @throws JsonException
     */
    public static function format(
        string $json,
        array $arrayKeys = [],
        int $indent = self::DEFAULT_INDENT,
        bool $formatJson = true,
    ): string {
        if ($formatJson) {
            $json = self::formatInternal($json, true, true);
        }

        if (4 !== $indent) {
            $json = str_replace('    ', str_repeat(' ', $indent), $json);
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
        preg_match_all(self::ARRAY_KEYS_REGEX, trim($content), $matches);

        return $matches[1];
    }

    /**
     * Get the indent of file.
     *
     * @param string $content The content
     */
    public static function getIndent(string $content): int
    {
        $indent = self::DEFAULT_INDENT;
        preg_match(self::INDENT_REGEX, trim($content), $matches);

        if (isset($matches[1])) {
            $indent = strlen($matches[1]);
        }

        return $indent;
    }

    /**
     * Format the data in JSON.
     *
     * @param bool $unescapeUnicode Un escape unicode.
     * @param bool $unescapeSlashes Un escape slashes.
     *
     * @throws JsonException
     */
    private static function formatInternal(string $json, bool $unescapeUnicode, bool $unescapeSlashes): string
    {
        if ($json === '') {
            return $json;
        }

        $array = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if ($unescapeUnicode) {
            array_walk_recursive(
                $array,
                static function (mixed &$item): void {
                    if (is_string($item)) {
                        $item = preg_replace_callback(
                            '/\\\\u([0-9a-fA-F]{4})/',
                            static fn(mixed $match): string => mb_convert_encoding(
                                pack('H*', $match[1]),
                                'UTF-8',
                                'UCS-2BE',
                            ),
                            $item,
                        );
                    }
                },
            );
        }

        if ($unescapeSlashes) {
            array_walk_recursive($array, static function (mixed &$item): void {
                if (is_string($item)) {
                    $item = str_replace('\\/', '/', $item);
                }
            });
        }

        return json_encode($array, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
                $replace = preg_replace('/\[\s*]/', '{}', $match[0]);
                if (null !== $replace) {
                    $json = str_replace($match[0], $replace, $json);
                }
            }
        }

        return $json;
    }
}
