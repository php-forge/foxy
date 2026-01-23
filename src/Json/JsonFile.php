<?php

declare(strict_types=1);

namespace Foxy\Json;

use Foxy\Exception\RuntimeException;

final class JsonFile extends \Composer\Json\JsonFile
{
    /**
     * @psalm-var string[]
     */
    private array $arrayKeys = [];

    /**
     * @psalm-var string[]
     */
    private static array $encodeArrayKeys = [];

    private static int $encodeIndent = JsonFormatter::DEFAULT_INDENT;

    private int|null $indent = null;

    public static function encode(mixed $data, int $options = 448, string $indent = self::INDENT_DEFAULT): string
    {
        $result = parent::encode($data, $options);

        return JsonFormatter::format($result, self::$encodeArrayKeys, self::$encodeIndent, false);
    }

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
     * Get the indent for this JSON file.
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
