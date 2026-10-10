<?php

declare(strict_types=1);

namespace Foxy\Native;

use Foxy\Exception\RuntimeException;

/**
 * Installs the frontend dependencies of a manifest into a flat directory without a Node.js package manager.
 */
interface NativeInstallerInterface
{
    /**
     * Installs the dependencies declared by the manifest, reading or rewriting the lock file.
     *
     * @param string $manifestPath Path of the root `package.json`.
     * @param string $lockPath Path of the `foxy.lock` file.
     * @param string $installDirectory Path of the directory to populate (`node_modules` by default).
     * @param bool $update Whether to resolve again even when the lock file is fresh.
     *
     * @throws RuntimeException if a requirement cannot be resolved, downloaded, or installed.
     */
    public function install(string $manifestPath, string $lockPath, string $installDirectory, bool $update): void;
}
