<?php

declare(strict_types=1);

namespace Foxy\Util;

use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Locker;

final class LockerUtil
{
    /**
     * Get the locker.
     */
    public static function getLocker(
        IOInterface $io,
        InstallationManager $im,
        string $composerFile,
    ): Locker {
        $lockFile = str_replace('.json', '.lock', $composerFile);

        return new Locker($io, new JsonFile($lockFile, null, $io), $im, file_get_contents($composerFile));
    }
}
