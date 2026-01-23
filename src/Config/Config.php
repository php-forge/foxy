<?php

declare(strict_types=1);

namespace Foxy\Config;

use Foxy\Exception\RuntimeException;

use function array_key_exists;
use function ctype_digit;
use function in_array;
use function is_array;
use function json_decode;
use function json_last_error;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtoupper;
use function trim;

class Config
{
    private array $cacheEnv = [];

    /**
     * @param array $config The config.
     * @param array $defaults The default values.
     */
    public function __construct(private readonly array $config, private readonly array $defaults = []) {}

    /**
     * Get the config value.
     *
     * @param string $key The config key.
     * @param mixed $default The default value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->cacheEnv)) {
            return $this->cacheEnv[$key];
        }

        $envKey = $this->convertEnvKey($key);
        $envValue = getenv($envKey);

        if (false !== $envValue) {
            return $this->cacheEnv[$key] = $this->convertEnvValue($envValue, $envKey);
        }

        $defaultValue = $this->getDefaultValue($key, $default);

        return array_key_exists($key, $this->config)
            ? $this->getByManager($key, $this->config[$key], $defaultValue)
            : $defaultValue;
    }

    /**
     * Get the array config value.
     *
     * @param string $key The config key.
     * @param array $default The default value.
     */
    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key);

        return is_array($value) ? $value : $default;
    }

    /**
     * Convert the value of environment variable into a boolean.
     *
     * @param string $value The value of environment variable.
     */
    private function convertBoolean(string $value): bool
    {
        return in_array($value, ['true', '1', 'yes', 'y'], true);
    }

    /**
     * Convert the config key into environment variable.
     *
     * @param string $key The config key.
     */
    private function convertEnvKey(string $key): string
    {
        return 'FOXY__' . strtoupper(str_replace('-', '_', $key));
    }

    /**
     * Convert the value of environment variable into php variable.
     *
     * @param string $value The value of environment variable.
     * @param string $environmentVariable The environment variable name.
     */
    private function convertEnvValue(string $value, string $environmentVariable): array|bool|int|string
    {
        $value = trim(trim(trim($value, '\''), '"'));

        if ($this->isBoolean($value)) {
            $value = $this->convertBoolean($value);
        } elseif ($this->isInteger($value)) {
            $value = $this->convertInteger($value);
        } elseif ($this->isJson($value)) {
            $value = $this->convertJson($value, $environmentVariable);
        }

        return $value;
    }

    /**
     * Convert the value of environment variable into an integer.
     *
     * @param string $value The value of environment variable.
     */
    private function convertInteger(string $value): int
    {
        return (int) $value;
    }

    /**
     * Convert the value of environment variable into a json array.
     *
     * @param string $value The value of environment variable.
     * @param string $environmentVariable The environment variable name.
     */
    private function convertJson(string $value, string $environmentVariable): array
    {
        $value = json_decode($value, true);

        if (json_last_error()) {
            throw new RuntimeException(
                sprintf('The "%s" environment variable isn\'t a valid JSON', $environmentVariable),
            );
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Get the value defined by the manager name in the key.
     *
     * @param string $key The config key.
     * @param mixed $value The value.
     * @param mixed  $default The default value.
     */
    private function getByManager(string $key, mixed $value, mixed $default = null): mixed
    {
        if (str_starts_with($key, 'manager-') && is_array($value)) {
            /** @var int|string $manager */
            $manager = $this->get('manager', '');

            $value = array_key_exists($manager, $value)
                ? $value[$manager]
                : $default;
        }

        return $value;
    }

    /**
     * Get the configured default value or custom default value.
     *
     * @param string $key The config key.
     * @param mixed $default The default value.
     */
    private function getDefaultValue(string $key, mixed $default = null): mixed
    {
        $value = null === $default &&  array_key_exists($key, $this->defaults)
            ? $this->defaults[$key]
            : $default;

        return $this->getByManager($key, $value, $default);
    }

    /**
     * Check if the value of environment variable is a boolean.
     *
     * @param string $value The value of environment variable.
     */
    private function isBoolean(string $value): bool
    {
        $value = strtolower($value);

        return in_array($value, ['true', 'false', '1', '0', 'yes', 'no', 'y', 'n'], true);
    }

    /**
     * Check if the value of environment variable is an integer.
     *
     * @param string $value The value of environment variable.
     */
    private function isInteger(string $value): bool
    {
        return ctype_digit(trim($value, '-'));
    }

    /**
     * Check if the value of environment variable is a string JSON.
     *
     * @param string $value The value of environment variable.
     */
    private function isJson(string $value): bool
    {
        return str_starts_with($value, '{') || str_starts_with($value, '[');
    }
}
