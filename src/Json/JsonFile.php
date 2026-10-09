<?php

declare(strict_types=1);

namespace Foxy\Json;

use Foxy\Exception\{Message, RuntimeException};

use function array_diff;
use function array_push;
use function is_array;
use function is_string;

final class JsonFile extends \Composer\Json\JsonFile
{
    private const int DEFAULT_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;

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

    /**
     * Encode package manifest data as JSON.
     *
     * Empty root arrays are encoded as objects because package manifests must have an object root.
     * The `$indent` argument is retained for Composer compatibility; Foxy always uses four spaces.
     */
    public static function encode(
        mixed $data,
        int $options = self::DEFAULT_OPTIONS,
        string $indent = self::INDENT_DEFAULT,
    ): string {
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
        $this->ensureParsed();

        return $this->arrayKeys;
    }

    /**
     * Get the indent for this JSON file.
     */
    public function getIndent(): int
    {
        return $this->ensureParsed();
    }

    public function read(): array
    {
        $data = parent::read();

        $this->getArrayKeys();

        return is_array($data) ? $data : [];
    }

    public function write(array $hash, int $options = self::DEFAULT_OPTIONS): void
    {
        $arrayKeys = $this->collectEmptyArrayKeys($hash);

        $mapKeys = [...$this->getMapKeys(), ...self::PACKAGE_MAP_KEYS];

        self::$encodeArrayKeys = array_diff($arrayKeys, $mapKeys);

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

            array_push($keys, ...$this->collectEmptyArrayKeys($value));
        }

        return $keys;
    }

    /**
     * Parses the original JSON document once and returns its detected indent.
     */
    private function ensureParsed(): int
    {
        if (null !== $this->indent) {
            return $this->indent;
        }

        $content = '';

        if ($this->exists()) {
            $path = $this->getPath();
            $content = file_get_contents($path);

            if (false === $content) {
                throw new RuntimeException(
                    Message::JSON_FILE_UNREADABLE->getMessage($path),
                );
            }
        }

        $this->arrayKeys = JsonFormatter::getArrayKeys($content);
        $this->mapKeys = JsonFormatter::getMapKeys($content);

        return $this->indent = JsonFormatter::getIndent($content);
    }

    /**
     * Get keys represented as empty objects in the original JSON document.
     *
     * @psalm-return string[]
     */
    private function getMapKeys(): array
    {
        $this->ensureParsed();

        return $this->mapKeys;
    }
}
