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

use Foxy\Exception\RuntimeException;

/**
 * The JSON file.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class JsonFile extends \Composer\Json\JsonFile
{
    /**
     * @psalm-var string[]
     */
    private array $arrayKeys = [];
    private int|null $indent = null;
    /**
     * @psalm-var string[]
     */
    private static array $encodeArrayKeys = [];
    private static int $encodeIndent = JsonFormatter::DEFAULT_INDENT;

    /**
     * Get the list of keys to be retained with an array representation if they are empty.
     *
     * @psalm-return string[]
     */
    public function getArrayKeys(): array
    {
        if ($this->arrayKeys === []) {
            $this->parseOriginalContent();
        }

        return $this->arrayKeys;
    }

    /**
     * Get the indent for this json file.
     */
    public function getIndent(): int
    {
        if ($this->indent === null) {
            $this->parseOriginalContent();
        }

        return $this->indent ?? JsonFormatter::DEFAULT_INDENT;
    }

    public function read(): array
    {
        $data = parent::read();

        $this->getArrayKeys();
        $this->getIndent();

        return is_array($data) ? $data : [];
    }

    public function write(array $hash, int $options = 448): void
    {
        self::$encodeArrayKeys = $this->getArrayKeys();
        self::$encodeIndent = JsonFormatter::DEFAULT_INDENT;
        parent::write($hash, $options);
        self::$encodeArrayKeys = [];
        self::$encodeIndent = JsonFormatter::DEFAULT_INDENT;
    }

    public static function encode(mixed $data, int $options = 448, string $indent = self::INDENT_DEFAULT): string
    {
        $result = parent::encode($data, $options, self::INDENT_DEFAULT);

        return JsonFormatter::format($result, self::$encodeArrayKeys, self::$encodeIndent, false);
    }

    /**
     * Parse the original content.
     */
    private function parseOriginalContent(): void
    {
        $content = '';

        if ($this->exists()) {
            $path = $this->getPath();
            $content = file_get_contents($path);

            if (false === $content) {
                throw new RuntimeException(sprintf('Unable to read json file "%s".', $path));
            }
        }
        $this->arrayKeys = JsonFormatter::getArrayKeys($content);
        $this->indent = JsonFormatter::getIndent($content);
    }
}
