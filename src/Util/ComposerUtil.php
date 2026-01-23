<?php

declare(strict_types=1);

namespace Foxy\Util;

use Composer\Installer\InstallerEvents;
use Composer\Semver\Semver;
use Foxy\Exception\RuntimeException;

final class ComposerUtil
{
    /**
     * Get the event name to init the plugin.
     */
    public static function getInitEventName(): string
    {
        return InstallerEvents::PRE_OPERATIONS_EXEC;
    }

    /**
     * Validate the composer version.
     *
     * @param string $requiredVersion The composer required version.
     * @param string $composerVersion The composer version.
     */
    public static function validateVersion(string $requiredVersion, string $composerVersion): void
    {
        $isBranch = str_contains($composerVersion, '@');
        $isSnapshot = (bool) preg_match('/^[0-9a-f]{40}$/i', $composerVersion);

        if (!$isBranch && !$isSnapshot && !Semver::satisfies($composerVersion, $requiredVersion)) {
            $msg = 'Foxy requires the Composer\'s minimum version "%s", current version is "%s"';

            throw new RuntimeException(sprintf($msg, $requiredVersion, $composerVersion));
        }
    }
}
