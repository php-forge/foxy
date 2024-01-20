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
            $version = substr($version, 0, $pos) . self::convertDateMinorVersion(substr($version, $pos + 1));
        }

        return $version;
    }

    /**
     * Converts the version metadata.
     */
    public static function convertVersionMetadata(string $version): string
    {
        if (preg_match_all(
            self::createPattern('([a-zA-Z]+|(\-|\+)[a-zA-Z]+|(\-|\+)[0-9]+)'),
            $version,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
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
        while (false !== strpos($version, '.x.x')) {
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
     */
    private static function cleanVersion(string $version, array $matches): array
    {
        $end = substr($version, \strlen($matches[1][0][0]));
        $version = $matches[1][0][0] . '-';

        $matches = [];
        if (preg_match('/^([-+])/', $end, $matches)) {
            $end = substr($end, 1);
        }

        $matches = [];
        preg_match('/^[a-z]+/', $end, $matches);
        $type = isset($matches[0]) ? VersionParser::normalizeStability($matches[0]) : '';
        $end = substr($end, \strlen($type));

        return [$type, $version, $end];
    }

    /**
     * Match the version.
     *
     * @param string $version The version.
     * @param string $type The type of version.
     *
     * @return array The list of $version and $patchVersion.
     */
    private static function matchVersion(string $version, string $type): array
    {
        $patchVersion = true;

        switch ($type) {
            case 'dev':
            case 'snapshot':
                $type = 'dev';
                $patchVersion = false;

                break;

            case 'a':
                $type = 'alpha';

                break;

            case 'b':
            case 'pre':
                $type = 'beta';

                break;

            default:
                if (!\in_array($type, ['alpha', 'beta', 'RC'], true)) {
                    $type = 'patch';
                }

                break;
        }

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
