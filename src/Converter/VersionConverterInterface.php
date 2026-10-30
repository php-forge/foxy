<?php

declare(strict_types=1);

namespace Foxy\Converter;

/**
 * Converts an asset-manager version to a Composer-compatible version.
 */
interface VersionConverterInterface
{
    /**
     * Converts the asset version to composer version.
     *
     * @param string|null $version The asset version
     *
     * @return string The composer version
     */
    public function convertVersion(string|null $version = null): string;
}
