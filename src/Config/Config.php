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

namespace Foxy\Config;

use Foxy\Exception\RuntimeException;

/**
 * Helper of package config.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
class Config
{
    private array $cacheEnv = [];

    /**
     * Constructor.
     *
     * @param array $config The config.
     * @param array $defaults The default values.
     */
    public function __construct(private array $config, private array $defaults = [])
    {
        $this->config = $config;
        $this->defaults = $defaults;
    }

    /**
     * Get the array config value.
     *
     * @param string $key The config key.
     * @param array $default The default value.
     */
    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key, null);

        return $value ?? $default;
    }

    /**
     * Get the config value.
     *
     * @param string $key The config key.
     * @param mixed $default The default value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (\array_key_exists($key, $this->cacheEnv)) {
            return $this->cacheEnv[$key];
        }

        $envKey = $this->convertEnvKey($key);
        $envValue = getenv($envKey);

        if (false !== $envValue) {
            return $this->cacheEnv[$key] = $this->convertEnvValue($envValue, $envKey);
        }

        $defaultValue = $this->getDefaultValue($key, $default);

        return \array_key_exists($key, $this->config)
            ? $this->getByManager($key, $this->config[$key], $defaultValue)
            : $defaultValue;
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
     * Check if the value of environment variable is a boolean.
     *
     * @param string $value The value of environment variable.
     */
    private function isBoolean(string $value): bool
    {
        $value = strtolower($value);

        return \in_array($value, array('true', 'false', '1', '0', 'yes', 'no', 'y', 'n'), true);
    }

    /**
     * Convert the value of environment variable into a boolean.
     *
     * @param string $value The value of environment variable.
     */
    private function convertBoolean(string $value): bool
    {
        return \in_array($value, array('true', '1', 'yes', 'y'), true);
    }

    /**
     * Check if the value of environment variable is a integer.
     *
     * @param string $value The value of environment variable.
     */
    private function isInteger(string $value): bool
    {
        return ctype_digit(trim($value, '-'));
    }

    /**
     * Convert the value of environment variable into a integer.
     *
     * @param string $value The value of environment variable.
     */
    private function convertInteger(string $value): int
    {
        return (int) $value;
    }

    /**
     * Check if the value of environment variable is a string JSON.
     *
     * @param string $value The value of environment variable.
     */
    private function isJson(string $value): bool
    {
        return 0 === strpos($value, '{') || 0 === strpos($value, '[');
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
                sprintf('The "%s" environment variable isn\'t a valid JSON', $environmentVariable)
            );
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
        $value = null === $default && \array_key_exists($key, $this->defaults)
            ? $this->defaults[$key]
            : $default;

        return $this->getByManager($key, $value, $default);
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
        if (0 === strpos($key, 'manager-') && \is_array($value)) {
            $manager = $manager = $this->get('manager', '');

            $value = \array_key_exists($manager, $value)
                ? $value[$manager]
                : $default;
        }

        return $value;
    }
}
