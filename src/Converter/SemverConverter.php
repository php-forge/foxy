<?php

declare(strict_types=1);

namespace Foxy\Converter;

use function in_array;
use function preg_match;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Converts an asset-manager version to a Composer-compatible version.
 */
final class SemverConverter implements VersionConverterInterface
{
    /**
     * Converts an asset-manager version to Composer-compatible syntax.
     */
    public function convertVersion(string|null $version = null): string
    {
        if (in_array($version, [null, '', 'latest'], true)) {
            return ('latest' === $version ? 'default || ' : '') . '*';
        }

        $version = str_replace('–', '-', $version);
        $prefix = preg_match('/^[a-z]/', $version) && !str_starts_with($version, 'dev-') ? $version[0] : '';
        $version = substr($version, strlen($prefix));

        $version = SemverUtil::convertVersionMetadata($version);
        $version = SemverUtil::convertDateVersion($version);

        return $prefix . $version;
    }
}
