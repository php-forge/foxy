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

namespace Foxy\Json;

/**
 * Formats JSON strings with a custom indent.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class JsonFormatter
{
    public const DEFAULT_INDENT = 4;
    public const ARRAY_KEYS_REGEX = '/["\']([\w\d_\-.]+)["\']:\s\[]/';
    public const INDENT_REGEX = '/^[{\[][\r\n]([ ]+)["\']/';

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

        return !empty($matches) ? $matches[1] : [];
    }

    /**
     * Get the indent of file.
     *
     * @param string $content The content
     */
    public static function getIndent(string $content): int
    {
        $indent = self::DEFAULT_INDENT;
        \preg_match(self::INDENT_REGEX, \trim($content), $matches);

        if (!empty($matches)) {
            $indent = \strlen($matches[1]);
        }

        return $indent;
    }

    /**
     * Format the data in JSON.
     *
     * @param string $json The original JSON.
     * @param array $arrayKeys The list of keys to be retained with an array representation if they are empty.
     * @param int $indent The space count for indent.
     * @param bool $formatJson Check if the json must be formatted.
     *
     * @psalm-param string[] $arrayKeys The list of keys to be retained with an array representation if they are empty.
     */
    public static function format(
        string $json,
        array $arrayKeys = [],
        $indent = self::DEFAULT_INDENT,
        $formatJson = true
    ): string {
        if ($formatJson) {
            $json = self::formatInternal($json, true, true);
        }

        if (4 !== $indent) {
            $json = \str_replace('    ', \str_repeat(' ', $indent), $json);
        }

        return self::replaceArrayByMap($json, $arrayKeys);
    }

    /**
     * Format the data in JSON.
     *
     * @param bool $unescapeUnicode Un escape unicode.
     * @param bool $unescapeSlashes Un escape slashes.
     */
    private static function formatInternal(string $json, bool $unescapeUnicode, bool $unescapeSlashes): string
    {
        $array = \json_decode($json, true);

        if (!is_array($array)) {
            return $json;
        }

        if ($unescapeUnicode) {
            \array_walk_recursive($array, function (mixed &$item): void {
                if (\is_string($item)) {
                    $item = \preg_replace_callback(
                        '/\\\\u([0-9a-fA-F]{4})/',
                        static function (mixed $match) {
                            $result = \mb_convert_encoding(\pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
                            return $result !== false ? $result : '';
                        },
                        $item,
                    );
                }
            });
        }

        if ($unescapeSlashes) {
            \array_walk_recursive($array, function (mixed &$item): void {
                if (\is_string($item)) {
                    $item = \str_replace('\\/', '/', $item);
                }
            });
        }

        return \json_encode($array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
        \preg_match_all(self::ARRAY_KEYS_REGEX, $json, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (!\in_array($match[1], $arrayKeys, true)) {
                $replace = \str_replace('[]', '{}', $match[0]);
                $json = \str_replace($match[0], $replace, $json);
            }
        }

        return $json;
    }
}
