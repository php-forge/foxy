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

namespace Foxy\Converter;

use Composer\Package\Version\VersionParser;

/**
 * Utils for semver converter.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
abstract class SemverUtil
{
    /**
     * Converts the date or datetime version.
     *
     * @param string $version The version.
     */
    public static function convertDateVersion(string $version): string
    {
        if (preg_match('/^\d{7,}\./', $version)) {
            $pos = strpos($version, '.');

            if ($pos !== false) {
                $version = substr($version, 0, $pos) . self::convertDateMinorVersion(substr($version, $pos + 1));
            }
        }

        return $version;
    }

    /**
     * Converts the version metadata.
     */
    public static function convertVersionMetadata(string $version): string
    {
        $pattern = self::createPattern('([a-zA-Z]+|(\-|\+)[a-zA-Z]+|(\-|\+)[0-9]+)');

        if (preg_match_all($pattern, $version, $matches, PREG_OFFSET_CAPTURE) > 0) {
            [$type, $version, $end] = self::cleanVersion(strtolower($version), $matches);
            [$version, $patchVersion] = self::matchVersion($version, $type);

            $matches = [];
            $hasPatchNumber = preg_match('/[0-9]+\.[0-9]+|[0-9]+|\.[0-9]+$/', $end, $matches);
            $end = $hasPatchNumber ? $matches[0] : '1';

            if ($patchVersion) {
                $version .= $end;
            }
        }

        return static::cleanWildcard($version);
    }

    /**
     * Creates a pattern with the version prefix pattern.
     *
     * @param string $pattern The pattern without '/'.
     *
     * @return string The full pattern with '/'.
     *
     * @psalm-return non-empty-string
     */
    public static function createPattern(string $pattern): string
    {
        $numVer = '([0-9]+|x|\*)';
        $numVer2 = '(' . $numVer . '\.' . $numVer . ')';
        $numVer3 = '(' . $numVer . '\.' . $numVer . '\.' . $numVer . ')';

        return '/^(' . $numVer . '|' . $numVer2 . '|' . $numVer3 . ')' . $pattern . '/';
    }

    /**
     * Clean the wildcard in version.
     *
     * @param string $version The version.
     *
     * @return string The cleaned version.
     */
    private static function cleanWildcard(string $version): string
    {
        while (str_contains($version, '.x.x')) {
            $version = str_replace('.x.x', '.x', $version);
        }

        return $version;
    }

    /**
     * Clean the raw version.
     *
     * @param string $version The version.
     * @param array $matches The match of pattern asset version.
     *
     * @return array The list of $type, $version and $end.
     *
     * @psalm-suppress MixedArrayAccess
     * @psalm-suppress MixedOperand
     *
     * @psalm-return array{0: string, 1: string, 2: string}
     */
    private static function cleanVersion(string $version, array $matches): array
    {
        $end = substr($version, \strlen((string) $matches[1][0][0]));
        $version = $matches[1][0][0] . '-';

        $matches = [];
        if (preg_match('/^([-+])/', $end, $matches)) {
            $end = substr($end, 1);
        }

        $matches = [];
        preg_match('/^[a-z]+/', $end, $matches);
        $type = isset($matches[0]) ? self::normalizeStability($matches[0]) : '';
        $end = substr($end, \strlen($type));

        return [$type, $version, $end];
    }

    /**
     * Normalize the stability.
     *
     * @param string $stability The stability.
     *
     * @return string The normalized stability.
     */
    private static function normalizeStability(string $stability): string
    {
        $stability = strtolower($stability);

        return match ($stability) {
            'a' => 'alpha',
            'b', 'pre' => 'beta',
            'build' => 'patch',
            'rc' => 'RC',
            'dev', 'snapshot' => 'dev',
            default => VersionParser::normalizeStability($stability),
        };
    }

    /**
     * Match the version.
     *
     * @param string $version The version.
     * @param string $type The type of version.
     *
     * @return array The list of $version and $patchVersion.
     *
     * @psalm-return array{0: string, 1: bool}
     */
    private static function matchVersion(string $version, string $type): array
    {
        $type = match ($type) {
            'dev', 'snapshot' => 'dev',
            default => \in_array($type, ['alpha', 'beta', 'RC'], true) ? $type : 'patch',
        };

        $patchVersion = !\in_array($type, ['dev'], true);

        $version .= $type;

        return [$version, $patchVersion];
    }

    /**
     * Convert the minor version of date.
     *
     * @param string $minor The minor version.
     */
    private static function convertDateMinorVersion(string $minor): string
    {
        $split = explode('.', $minor);
        $minor = (int) $split[0];
        $revision = isset($split[1]) ? (int) $split[1] : 0;

        return '.' . sprintf('%03d', $minor) . sprintf('%03d', $revision);
    }
}
