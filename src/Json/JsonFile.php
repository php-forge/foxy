<?php

declare(strict_types=1);

namespace Foxy\Json;

use Foxy\Exception\RuntimeException;

use function array_diff;
use function array_unique;
use function array_values;
use function is_array;
use function is_string;
use function sprintf;

final class JsonFile extends \Composer\Json\JsonFile
{
    private const array PACKAGE_MAP_KEYS = [
        'dependencies',
        'devDependencies',
        'optionalDependencies',
        'overrides',
        'peerDependencies',
        'peerDependenciesMeta',
        'resolutions',
    ];

    /**
     * @psalm-var string[]
     */
    private array $arrayKeys = [];

    /**
     * @psalm-var string[]
     */
    private static array $encodeArrayKeys = [];

    private int|null $indent = null;

    private array $mapKeys = [];

    private bool $parsed = false;

    public static function encode(mixed $data, int $options = 448, string $indent = self::INDENT_DEFAULT): string
    {
        $result = parent::encode([] === $data ? (object) [] : $data, $options);

        return JsonFormatter::format($result, self::$encodeArrayKeys, JsonFormatter::DEFAULT_INDENT, false);
    }

    /**
     * Get the list of keys to be retained with an array representation if they are empty.
     *
     * @psalm-return string[]
     */
    public function getArrayKeys(): array
    {
        if (!$this->parsed) {
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

        return is_array($data) ? $data : [];
    }

    public function write(array $hash, int $options = 448): void
    {
        $arrayKeys = $this->collectEmptyArrayKeys($hash);

        $mapKeys = [...$this->getMapKeys(), ...self::PACKAGE_MAP_KEYS];

        self::$encodeArrayKeys = array_values(
            array_unique([...$this->getArrayKeys(), ...array_diff($arrayKeys, $mapKeys)]),
        );

        try {
            parent::write($hash, $options);
        } finally {
            self::$encodeArrayKeys = [];
        }
    }

    /**
     * Collect keys whose values are empty PHP arrays.
     *
     * @psalm-return string[]
     */
    private function collectEmptyArrayKeys(array $data): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if ([] === $value && is_string($key)) {
                $keys[] = $key;
                continue;
            }

            $keys = [...$keys, ...$this->collectEmptyArrayKeys($value)];
        }

        return $keys;
    }

    /**
     * Get keys represented as empty objects in the original JSON document.
     *
     * @psalm-return string[]
     */
    private function getMapKeys(): array
    {
        if (!$this->parsed) {
            $this->parseOriginalContent();
        }

        return $this->mapKeys;
    }

    private function parseOriginalContent(): void
    {
        $content = '';

        if ($this->exists()) {
            $path = $this->getPath();
            $content = file_get_contents($path);

            if (false === $content) {
                throw new RuntimeException(
                    sprintf('Unable to read json file "%s".', $path),
                );
            }
        }

        $this->arrayKeys = JsonFormatter::getArrayKeys($content);
        $this->mapKeys = JsonFormatter::getMapKeys($content);
        $this->indent = JsonFormatter::getIndent($content);

        $this->parsed = true;
    }
}
