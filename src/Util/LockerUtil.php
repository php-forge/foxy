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

namespace Foxy\Util;

use Composer\Composer;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Locker;
use Composer\Repository\RepositoryManager;

/**
 * Helper for Locker.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class LockerUtil
{
    /**
     * Get the locker.
     */
    public static function getLocker(
        IOInterface $io,
        InstallationManager $im,
        string $composerFile
    ): Locker {
        $lockFile = str_replace('.json', '.lock', $composerFile);
        
        return new Locker($io, new JsonFile($lockFile, null, $io), $im, file_get_contents($composerFile));
    }
}
