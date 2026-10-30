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
 * Converts asset-manager version constraints to Composer-compatible constraints.
 */
final class SemverConverter implements VersionConverterInterface
{
    /**
     * Converts an asset-manager version constraint to Composer syntax.
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
