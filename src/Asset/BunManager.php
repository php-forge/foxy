<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\Pcre\Preg;
use Composer\Util\Platform;
use Foxy\Exception\{Message, RuntimeException};
use Generator;
use Stringable;

use function array_diff_ukey;
use function array_intersect_key;
use function array_intersect_ukey;
use function array_key_exists;
use function array_pop;
use function file_exists;
use function getenv;
use function in_array;
use function is_file;
use function is_readable;
use function is_scalar;
use function ltrim;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

final class BunManager extends AbstractAuditableAssetManager
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

        return $this->buildUnconfiguredCommand($this->getBinary(), $command);
    }

    protected function getInstallCommand(): string
    {
        return $this->buildCommand($this->getBinary(), 'install', 'install');
    }

    protected function getUpdateCommand(): string
    {
        return $this->buildCommand($this->getBinary(), 'update', 'update');
    }

    protected function getVersionCommand(): string
    {
        return $this->buildUnconfiguredCommand($this->getBinary(), '--version');
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
            return $this->normalizeAuditEnvironmentValue($environment[$name]);
        }

        if (Platform::isWindows()) {
            foreach ($environment as $key => $value) {
                if (0 === strcasecmp((string) $key, $name)) {
                    return $this->normalizeAuditEnvironmentValue($value);
                }
            }
        }

        return null;
    }

    /**
     * Returns the platform-specific Bun binary name.
     */
    private function getBinary(): string
    {
        return Platform::isWindows() ? 'bun.exe' : 'bun';
    }

    /**
     * @return list<string>
     */
    private function getBunfigPaths(): array
    {
        $configHome = $this->getAuditEnvironmentValue('XDG_CONFIG_HOME') ?? $this->getHomeDirectory();

        $paths = [];

        if (null !== $configHome) {
            $paths[] = $this->getConfigurationPath($configHome, '.bunfig.toml');
        }

        $paths[] = $this->getRootPackagePath('bunfig.toml');

        return $paths;
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

        return $paths;
    }

    private function isSingleLineTomlContainer(string $value): bool
    {
        $closingCharacters = [];

        $characters = $this->scanUnquotedTomlCharacters($value);

        foreach ($characters as $character) {
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

        return [] === $closingCharacters && $characters->getReturn();
    }

    private function normalizeAuditEnvironmentValue(mixed $value): string|null
    {
        if (!is_scalar($value) && !$value instanceof Stringable) {
            return null;
        }

        $value = (string) $value;

        return '' === $value ? null : $value;
    }

    private function readAuditConfiguration(string $path): string|null
    {
        if (!file_exists($path)) {
            return null;
        }

        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if (false === $contents) {
            throw new RuntimeException(
                Message::ASSET_BUN_AUDIT_CONFIG_UNREADABLE->getMessage($path),
            );
        }

        return $contents;
    }

    private function rejectRestrictedAuditScope(string $path, string $setting): never
    {
        throw new RuntimeException(
            Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED->getMessage($path, $setting),
        );
    }

    private function rejectUnverifiableAuditConfiguration(string $path, string $reason): never
    {
        throw new RuntimeException(
            Message::ASSET_BUN_AUDIT_SCOPE_UNVERIFIABLE->getMessage($path, $reason),
        );
    }

    /**
     * Yields the characters outside TOML basic and literal strings, keyed by position, and returns whether every string
     * is closed.
     *
     * @return Generator<int, string, mixed, bool>
     */
    private function scanUnquotedTomlCharacters(string $value): Generator
    {
        $quote = null;

        for ($position = 0, $length = strlen($value); $position < $length; ++$position) {
            $character = $value[$position];

            if ('\\' === $quote) {
                $quote = '"';

                continue;
            }

            if (null !== $quote) {
                if ('"' === $quote && '\\' === $character) {
                    $quote = '\\';
                } elseif ($quote === $character) {
                    $quote = null;
                }

                continue;
            }

            if ('"' === $character || '\'' === $character) {
                $quote = $character;

                continue;
            }

            yield $position => $character;
        }

        return null === $quote;
    }

    private function stripLeadingUtf8Bom(string $contents): string
    {
        return Preg::replace('/\A(?:\xEF\xBB\xBF)++/', '', $contents);
    }

    private function stripTomlComment(string $line): string
    {
        foreach ($this->scanUnquotedTomlCharacters($line) as $position => $character) {
            if ('#' === $character) {
                return substr($line, 0, $position);
            }
        }

        return $line;
    }

    private function validateBunfigAuditScope(string $contents, string $path, bool $noDev): void
    {
        if (1 !== preg_match('//u', $contents)) {
            $this->rejectUnverifiableAuditConfiguration(
                $path,
                Message::ASSET_BUN_AUDIT_REASON_UTF8_REQUIRED->getMessage(),
            );
        }

        $contents = $this->stripLeadingUtf8Bom($contents);

        $inInstallSection = false;

        $lines = Preg::split('/\R/u', $contents);

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
                    Message::ASSET_BUN_AUDIT_REASON_TOML_KEY_ESCAPES->getMessage(),
                );
            }

            if ($inInstallSection && false !== $assignmentPosition) {
                $value = ltrim(substr($line, $assignmentPosition + 1));

                if (str_contains($value, '"""') || str_contains($value, "'''")) {
                    $this->rejectUnverifiableAuditConfiguration(
                        $path,
                        Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_STRING->getMessage(),
                    );
                }

                if (
                    '' !== $value
                    && ('[' === $value[0] || '{' === $value[0])
                    && !$this->isSingleLineTomlContainer($value)
                ) {
                    $this->rejectUnverifiableAuditConfiguration(
                        $path,
                        Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_CONTAINER->getMessage(),
                    );
                }
            }

            if ('[' === $line[0]) {
                $arrayTable = str_starts_with($line, '[[');
                $tableName = trim(substr($line, $arrayTable ? 2 : 1, $arrayTable ? -2 : -1));

                $inInstallSection = str_ends_with($line, ']')
                    && in_array($tableName, ['install', '"install"', "'install'"], true);

                if ($arrayTable && $inInstallSection) {
                    $this->rejectUnverifiableAuditConfiguration(
                        $path,
                        Message::ASSET_BUN_AUDIT_REASON_TOML_ARRAY_INSTALL_TABLE->getMessage(),
                    );
                }

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
            }

            if (
                1 === preg_match(
                    '/^(?:"install"|\'install\'|install)\s*=\s*\{/',
                    $line,
                )
            ) {
                $this->rejectUnverifiableAuditConfiguration(
                    $path,
                    Message::ASSET_BUN_AUDIT_REASON_TOML_INLINE_INSTALL_TABLE->getMessage(),
                );
            }
        }
    }

    private function validateBunScopeSetting(string $name, bool $value, string $path, bool $noDev): void
    {
        $includesRequiredScope = 'production' === $name ? !$value : $value;
        $appliesToAudit = !$noDev || 'optional' === $name || 'peer' === $name;

        if ($appliesToAudit && !$includesRequiredScope) {
            $this->rejectRestrictedAuditScope($path, 'install.' . $name . '=' . ($value ? 'true' : 'false'));
        }
    }

    private function validateNpmrcAuditScope(string $contents, string $path, bool $noDev): void
    {
        if (1 !== preg_match('//u', $contents)) {
            $this->rejectUnverifiableAuditConfiguration(
                $path,
                Message::ASSET_BUN_AUDIT_REASON_UTF8_REQUIRED->getMessage(),
            );
        }

        $contents = $this->stripLeadingUtf8Bom($contents);

        $lines = Preg::split('/\R/u', $contents);

        foreach ($lines as $line) {
            $line = ltrim($line);

            $assignmentPosition = strpos($line, '=');
            $keyExpression = false === $assignmentPosition ? $line : substr($line, 0, $assignmentPosition);

            if (
                '' !== $keyExpression
                && ('"' === $keyExpression[0] || '\'' === $keyExpression[0])
                && str_contains($keyExpression, '\\')
            ) {
                $this->rejectUnverifiableAuditConfiguration(
                    $path,
                    Message::ASSET_BUN_AUDIT_REASON_NPMRC_KEY_ESCAPES->getMessage(),
                );
            }

            if (false === $assignmentPosition) {
                continue;
            }

            $key = strtolower(trim($keyExpression, " \t'\""));

            if ('omit' !== $key && 'omit[]' !== $key) {
                continue;
            }

            $value = trim(substr($line, $assignmentPosition + 1));

            if (str_contains($value, '${')) {
                $this->rejectRestrictedAuditScope($path, 'dynamic omit=' . $value);
            }

            if (str_contains($value, '\\')) {
                $this->rejectUnverifiableAuditConfiguration(
                    $path,
                    Message::ASSET_BUN_AUDIT_REASON_NPMRC_OMIT_ESCAPES->getMessage(),
                );
            }

            $values = Preg::split('/[\s,;]+/u', $value);

            foreach ($values as $omitted) {
                $omitted = strtolower(trim($omitted, " \t\n\r\0\x0B[]'\""));

                if ('optional' === $omitted || 'peer' === $omitted || ('dev' === $omitted && !$noDev)) {
                    $this->rejectRestrictedAuditScope($path, "omit={$omitted}");
                }
            }
        }
    }
}
