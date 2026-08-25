<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\Util\Platform;
use Foxy\Exception\RuntimeException;
use Stringable;

use function array_diff_ukey;
use function array_intersect_key;
use function array_intersect_ukey;
use function array_key_exists;
use function array_pop;
use function array_unique;
use function array_values;
use function file_exists;
use function file_get_contents;
use function getenv;
use function is_file;
use function is_readable;
use function is_scalar;
use function ltrim;
use function preg_match;
use function preg_split;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

final class BunManager extends AbstractAssetManager
{
    public function getLockPackageName(): string
    {
        return 'bun.lock';
    }

    public function getName(): string
    {
        return 'bun';
    }

    public function getVersionConstraint(): string
    {
        return '^1.4.0';
    }

    public function isInstalled(): bool
    {
        return parent::isInstalled() && $this->hasLockFile();
    }

    protected function getAuditCommand(bool $noDev): string
    {
        $command = ['audit', '--json'];

        if ($noDev) {
            $command[] = '--prod';
        }

        $binary = Platform::isWindows() ? 'bun.exe' : 'bun';

        return $this->buildUnconfiguredCommand($binary, $command);
    }

    protected function getInstallCommand(): string
    {
        $command = Platform::isWindows() ? 'bun.exe' : 'bun';

        return $this->buildCommand($command, 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        $command = Platform::isWindows() ? 'bun.exe' : 'bun';

        return $this->buildCommand($command, 'update', 'update');
    }

    protected function getVersionCommand(): string
    {
        $command = Platform::isWindows() ? 'bun.exe' : 'bun';

        return $this->buildUnconfiguredCommand($command, '--version');
    }

    protected function validateAuditConfiguration(bool $noDev): void
    {
        foreach ($this->getNpmrcPaths() as $path) {
            $contents = $this->readAuditConfiguration($path);

            if (null !== $contents) {
                $this->validateNpmrcAuditScope($contents, $path, $noDev);
            }
        }

        foreach ($this->getBunfigPaths() as $path) {
            $contents = $this->readAuditConfiguration($path);

            if (null !== $contents) {
                $this->validateBunfigAuditScope($contents, $path, $noDev);
            }
        }
    }

    /**
     * Read the environment using the same merge order as Symfony Process.
     */
    private function getAuditEnvironmentValue(string $name): string|null
    {
        $environment = getenv();

        if (Platform::isWindows()) {
            $serverEnvironment = array_intersect_ukey($environment, $_SERVER, 'strcasecmp');

            $environment = [] === $serverEnvironment ? $environment : $serverEnvironment;

            $environment = $_ENV + array_diff_ukey($environment, $_ENV, 'strcasecmp');
        } else {
            $serverEnvironment = array_intersect_key($environment, $_SERVER);

            $environment = $_ENV + ([] === $serverEnvironment ? $environment : $serverEnvironment);
        }

        if (array_key_exists($name, $environment)) {
            $value = $environment[$name];

            return (is_scalar($value) || $value instanceof Stringable) && false !== $value && '' !== (string) $value
                ? (string) $value
                : null;
        }

        if (Platform::isWindows()) {
            foreach ($environment as $key => $value) {
                if (
                    0 === strcasecmp((string) $key, $name)
                    && (is_scalar($value) || $value instanceof Stringable)
                    && false !== $value
                    && '' !== (string) $value
                ) {
                    return (string) $value;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function getBunfigPaths(): array
    {
        $xdgConfigHome = $this->getAuditEnvironmentValue('XDG_CONFIG_HOME');
        $home = $this->getHomeDirectory();

        $paths = [];

        if (null !== $xdgConfigHome) {
            $paths[] = $this->getConfigurationPath($xdgConfigHome, '.bunfig.toml');
        } elseif (null !== $home) {
            $paths[] = $this->getConfigurationPath($home, '.bunfig.toml');
        }

        $paths[] = $this->getRootPackagePath('bunfig.toml');

        return array_values(array_unique($paths));
    }

    private function getConfigurationPath(string $directory, string $file): string
    {
        return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $file;
    }

    private function getHomeDirectory(): string|null
    {
        $home = $this->getAuditEnvironmentValue('HOME') ?? $this->getAuditEnvironmentValue('USERPROFILE');

        if (null !== $home) {
            return $home;
        }

        $homeDrive = $this->getAuditEnvironmentValue('HOMEDRIVE');
        $homePath = $this->getAuditEnvironmentValue('HOMEPATH');

        return null !== $homeDrive && null !== $homePath ? $homeDrive . $homePath : null;
    }

    /**
     * @return list<string>
     */
    private function getNpmrcPaths(): array
    {
        $xdgConfigHome = $this->getAuditEnvironmentValue('XDG_CONFIG_HOME');
        $home = $this->getHomeDirectory();

        $paths = [];

        if (null !== $xdgConfigHome) {
            $xdgPath = $this->getConfigurationPath($xdgConfigHome, '.npmrc');

            if (file_exists($xdgPath)) {
                $paths[] = $xdgPath;
            } elseif (null !== $home) {
                $paths[] = $this->getConfigurationPath($home, '.npmrc');
            }
        } elseif (null !== $home) {
            $paths[] = $this->getConfigurationPath($home, '.npmrc');
        }

        $paths[] = $this->getRootPackagePath('.npmrc');

        return array_values(array_unique($paths));
    }

    private function isSingleLineTomlContainer(string $value): bool
    {
        $closingCharacters = [];
        $inDoubleQuote = false;
        $inSingleQuote = false;
        $escaped = false;

        for ($position = 0, $length = strlen($value); $position < $length; ++$position) {
            $character = $value[$position];

            if ($inDoubleQuote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ('"' === $character) {
                    $inDoubleQuote = false;
                }

                continue;
            }

            if ($inSingleQuote) {
                if ('\'' === $character) {
                    $inSingleQuote = false;
                }

                continue;
            }

            if ('"' === $character) {
                $inDoubleQuote = true;

                continue;
            }

            if ('\'' === $character) {
                $inSingleQuote = true;

                continue;
            }

            if ('[' === $character) {
                $closingCharacters[] = ']';

                continue;
            }

            if ('{' === $character) {
                $closingCharacters[] = '}';

                continue;
            }

            if (']' === $character || '}' === $character) {
                if ([] === $closingCharacters || $character !== array_pop($closingCharacters)) {
                    return false;
                }
            }
        }

        return [] === $closingCharacters && !$inDoubleQuote && !$inSingleQuote;
    }

    private function readAuditConfiguration(string $path): string|null
    {
        if (!file_exists($path)) {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(
                sprintf('The Bun audit configuration "%s" cannot be read.', $path),
            );
        }

        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new RuntimeException(
                sprintf('The Bun audit configuration "%s" cannot be read.', $path),
            );
        }

        return $contents;
    }

    private function rejectRestrictedAuditScope(string $path, string $setting): never
    {
        throw new RuntimeException(
            sprintf(
                'The Bun audit cannot guarantee the requested dependency scope because "%s" declares "%s".',
                $path,
                $setting,
            ),
        );
    }

    private function rejectUnverifiableAuditConfiguration(string $path, string $reason): never
    {
        throw new RuntimeException(
            sprintf('The Bun audit cannot verify dependency scope in "%s": %s.', $path, $reason),
        );
    }

    private function stripTomlComment(string $line): string
    {
        $inDoubleQuote = false;
        $inSingleQuote = false;
        $escaped = false;

        for ($position = 0, $length = strlen($line); $position < $length; ++$position) {
            $character = $line[$position];

            if ($inDoubleQuote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ('"' === $character) {
                    $inDoubleQuote = false;
                }

                continue;
            }

            if ($inSingleQuote) {
                if ('\'' === $character) {
                    $inSingleQuote = false;
                }

                continue;
            }

            if ('"' === $character) {
                $inDoubleQuote = true;

                continue;
            }

            if ('\'' === $character) {
                $inSingleQuote = true;

                continue;
            }

            if ('#' === $character) {
                return substr($line, 0, $position);
            }
        }

        return $line;
    }

    private function validateBunfigAuditScope(string $contents, string $path, bool $noDev): void
    {
        if (1 !== preg_match('//u', $contents)) {
            $this->rejectUnverifiableAuditConfiguration($path, 'the configuration must be UTF-8');
        }

        while (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $inInstallSection = false;

        $lines = preg_split('/\R/u', $contents);

        $lines = false === $lines ? [] : $lines;

        foreach ($lines as $line) {
            $line = trim($this->stripTomlComment($line));

            if ('' === $line) {
                continue;
            }

            $assignmentPosition = strpos($line, '=');
            $keyExpression = false === $assignmentPosition ? $line : substr($line, 0, $assignmentPosition);

            if (str_contains($keyExpression, '\\')) {
                $this->rejectUnverifiableAuditConfiguration(
                    $path,
                    'escape sequences in TOML keys are not supported; use canonical keys',
                );
            }

            if ($inInstallSection && false !== $assignmentPosition) {
                $value = ltrim(substr($line, $assignmentPosition + 1));

                if (str_contains($value, '"""') || str_contains($value, "'''")) {
                    $this->rejectUnverifiableAuditConfiguration(
                        $path,
                        'multiline strings in [install] are not supported',
                    );
                }

                if (
                    '' !== $value
                    && ('[' === $value[0] || '{' === $value[0])
                    && !$this->isSingleLineTomlContainer($value)
                ) {
                    $this->rejectUnverifiableAuditConfiguration(
                        $path,
                        'multiline container values in [install] are not supported',
                    );
                }
            }

            if (1 === preg_match('/^\[\s*(?:"install"|\'install\'|install)\s*\]$/', $line)) {
                $inInstallSection = true;

                continue;
            }

            if (1 === preg_match('/^\[\[\s*(?:"install"|\'install\'|install)\s*\]\]$/', $line)) {
                $this->rejectUnverifiableAuditConfiguration(
                    $path,
                    'array install tables are not supported; use an [install] table',
                );
            }

            if ('[' === $line[0]) {
                $inInstallSection = false;

                continue;
            }

            if (
                $inInstallSection
                && 1 === preg_match(
                    '/^(?:"|\')?(production|dev|optional|peer)(?:"|\')?\s*=\s*(true|false)\b/',
                    $line,
                    $matches,
                )
            ) {
                $this->validateBunScopeSetting($matches[1], 'true' === $matches[2], $path, $noDev);

                continue;
            }

            if (
                1 === preg_match(
                    '/^(?:"install"|\'install\'|install)\s*\.\s*(?:"|\')?'
                    . '(production|dev|optional|peer)(?:"|\')?\s*=\s*(true|false)\b/',
                    $line,
                    $matches,
                )
            ) {
                $this->validateBunScopeSetting($matches[1], 'true' === $matches[2], $path, $noDev);

                continue;
            }

            if (
                1 === preg_match(
                    '/^(?:"install"|\'install\'|install)\s*=\s*\{/',
                    $line,
                )
            ) {
                $this->rejectUnverifiableAuditConfiguration(
                    $path,
                    'inline install tables are not supported; use an [install] table',
                );
            }
        }
    }

    private function validateBunScopeSetting(string $name, bool $value, string $path, bool $noDev): void
    {
        $restricted = match ($name) {
            'production' => $value && !$noDev,
            'dev' => !$value && !$noDev,
            'optional', 'peer' => !$value,
            default => false,
        };

        if ($restricted) {
            $this->rejectRestrictedAuditScope($path, 'install.' . $name . '=' . ($value ? 'true' : 'false'));
        }
    }

    private function validateNpmrcAuditScope(string $contents, string $path, bool $noDev): void
    {
        if (1 !== preg_match('//u', $contents)) {
            $this->rejectUnverifiableAuditConfiguration($path, 'the configuration must be UTF-8');
        }

        while (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $lines = preg_split('/\R/u', $contents);

        $lines = false === $lines ? [] : $lines;

        foreach ($lines as $line) {
            $line = ltrim($line);

            if ('' === $line || '#' === $line[0] || ';' === $line[0]) {
                continue;
            }

            $assignmentPosition = strpos($line, '=');
            $keyExpression = false === $assignmentPosition ? $line : trim(substr($line, 0, $assignmentPosition));

            if (
                '' !== $keyExpression
                && ('"' === $keyExpression[0] || '\'' === $keyExpression[0])
                && str_contains($keyExpression, '\\')
            ) {
                $this->rejectUnverifiableAuditConfiguration(
                    $path,
                    'escape sequences in npmrc keys are not supported; use canonical keys',
                );
            }

            if (
                1 !== preg_match(
                    '/^(?:"omit(?:\[\])?"|\'omit(?:\[\])?\'|omit(?:\[\])?)\s*=\s*(.*)$/i',
                    $line,
                    $matches,
                )
            ) {
                continue;
            }

            $value = trim($matches[1]);

            if ('' === $value) {
                continue;
            }

            if (str_contains($value, '${')) {
                $this->rejectRestrictedAuditScope($path, 'dynamic omit=' . $value);
            }

            if (str_contains($value, '\\')) {
                $this->rejectUnverifiableAuditConfiguration(
                    $path,
                    'escape sequences in npmrc omit values are not supported; use canonical values',
                );
            }

            $values = preg_split('/[\s,;]+/u', $value);
            $values = false === $values ? [] : $values;

            foreach ($values as $omitted) {
                $omitted = strtolower(trim($omitted, " \t\n\r\0\x0B[]'\""));

                if ('optional' === $omitted || 'peer' === $omitted || ('dev' === $omitted && !$noDev)) {
                    $this->rejectRestrictedAuditScope($path, "omit={$omitted}");
                }
            }
        }
    }
}
