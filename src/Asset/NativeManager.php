<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\IO\IOInterface;
use Composer\Util\{Filesystem, Platform};
use Foxy\Config\Config;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Fallback\FallbackInterface;
use Foxy\Native\NativeInstallerInterface;
use Throwable;

use function array_unshift;
use function basename;
use function dirname;
use function implode;
use function is_dir;
use function is_string;
use function preg_match;
use function realpath;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Provides the `native` asset manager: installs `package.json` dependencies from the npm registry in PHP, recording
 * them in `foxy.lock`, without a Node.js package manager.
 *
 * Never selected automatically unless `foxy.lock` exists; `manager: native` selects it explicitly. Packages are installed
 * into the `native-install-dir` directory (`node_modules` by default).
 */
final class NativeManager extends AbstractManifestAssetManager
{
    private const string FILESYSTEM_ROOT = '{^(?:[A-Za-z]:)?/?$}';

    /**
     * @param IOInterface $io Output for the progress line.
     * @param Config $config Foxy configuration.
     * @param Filesystem $fs Filesystem used by the manifest handling.
     * @param NativeInstallerInterface $installer Installer that populates the install directory.
     * @param FallbackInterface|null $fallback Fallback restored when the installation fails, or `null` for none.
     */
    public function __construct(
        IOInterface $io,
        Config $config,
        Filesystem $fs,
        private readonly NativeInstallerInterface $installer,
        FallbackInterface|null $fallback = null,
    ) {
        parent::__construct($io, $config, $fs, $fallback);
    }

    public function getLockPackageName(): string
    {
        return 'foxy.lock';
    }

    public function getName(): string
    {
        return 'native';
    }

    public function getVersionConstraint(): string
    {
        return '*';
    }

    /**
     * Records `native` as the resolved manager and returns whether `foxy.lock` exists.
     */
    public function isAvailable(): bool
    {
        $this->config->setResolvedManager($this->getName());

        return $this->hasLockFile();
    }

    /**
     * Returns whether the install directory and `package.json` exist.
     *
     * @throws RuntimeException if the configured install directory is the root package directory, one of its parents,
     * or a filesystem root, or if a symbolic link in it or in the root package directory cannot be resolved.
     */
    public function isInstalled(): bool
    {
        return is_dir($this->getInstallDirectory()) && file_exists($this->getPackageJsonPath());
    }

    /**
     * Installs the dependencies, or updates them when updates are allowed by {@see setUpdatable()}, as `composer update`
     * does, even if nothing is installed yet.
     *
     * Returns `0` without doing anything when `run-asset-manager` is disabled. The fallback is restored when the
     * installation fails, and the installer exception is rethrown.
     *
     * @throws RuntimeException if the `zlib` extension is missing, the configured root package directory does not
     * exist, the install directory is invalid, the installation fails, or the fallback cannot be restored after a
     * failure.
     */
    public function run(): int
    {
        if (!$this->config->isEnabled('run-asset-manager')) {
            return 0;
        }

        $this->validate();
        $this->getManagerWorkingDirectory();

        $installDirectory = $this->getInstallDirectory();
        $updatable = $this->updatable;

        $this->io->write(
            sprintf(
                '<info>%s frontend dependencies with the native manager</info>',
                $updatable ? 'Updating' : 'Installing',
            ),
        );

        try {
            $this->installer->install(
                $this->getPackageJsonPath(),
                $this->getLockFilePath(),
                $installDirectory,
                $updatable,
            );
        } catch (Throwable $exception) {
            $this->restoreAfterFailure($exception);

            throw $exception;
        }

        return 0;
    }

    /**
     * Checks that the `zlib` extension, which reads the gzip-compressed tarballs, is loaded.
     *
     * @throws RuntimeException if the `zlib` extension is missing.
     */
    public function validate(): void
    {
        if (!extension_loaded('zlib')) {
            throw new RuntimeException(
                Message::NATIVE_EXTENSION_MISSING->getMessage('zlib'),
            );
        }
    }

    /**
     * Returns a normalized absolute path with the symbolic links of its longest existing prefix resolved and the
     * missing suffix appended.
     *
     * Links are resolved only where `realpath()` resolves them, which excludes Windows.
     *
     * @throws RuntimeException if the existing prefix cannot be resolved, such as a dangling symbolic link.
     */
    private function canonicalPath(string $path): string
    {
        $suffix = [];
        $existingPath = $path;

        while (!file_exists($existingPath) && !is_link($existingPath)) {
            $parent = $this->fs->normalizePath(dirname($existingPath));

            if ($parent === $existingPath) {
                break;
            }

            array_unshift($suffix, basename($existingPath));

            $existingPath = $parent;
        }

        $resolvedPath = realpath($existingPath);

        if (false === $resolvedPath) {
            throw new RuntimeException(Message::SOLVER_PATH_RESOLVE_FAILED->getMessage($path));
        }

        return $this->fs->normalizePath($resolvedPath . '/' . implode('/', $suffix));
    }

    /**
     * Returns a normalized path in the form used for comparisons: lowercased on Windows, where paths are
     * case-insensitive, and unchanged elsewhere.
     */
    private function comparablePath(string $path): string
    {
        return Platform::isWindows() ? strtolower($path) : $path;
    }

    /**
     * Returns the normalized directory the packages are installed into.
     *
     * The `native-install-dir` value defaults to `node_modules` when it is not a string or is blank; a relative value
     * is resolved against the root package directory. Normalization also drops trailing slashes and backslashes. The
     * checks compare the canonical forms of both directories, with symbolic links resolved, and ignore letter case on
     * Windows; the returned path keeps its symbolic links.
     *
     * @throws RuntimeException if the directory is the root package directory, one of its parents, or a filesystem
     * root, since the installer removes every entry of the directory outside the install set, or if a symbolic link in
     * either directory cannot be resolved.
     */
    private function getInstallDirectory(): string
    {
        $value = $this->config->get('native-install-dir');
        $directory = is_string($value) ? trim($value) : '';

        if ('' === $directory) {
            $directory = 'node_modules';
        }

        $path = $this->fs->normalizePath(
            $this->fs->isAbsolutePath($directory) ? $directory : $this->getRootPackagePath($directory),
        );
        $canonicalPath = $this->canonicalPath($path);
        $comparablePath = $this->comparablePath($canonicalPath);
        $root = $this->comparablePath($this->canonicalPath($this->fs->normalizePath($this->getRootPackageDir())));

        if (1 === preg_match(self::FILESYSTEM_ROOT, $path)
            || 1 === preg_match(self::FILESYSTEM_ROOT, $canonicalPath)
            || str_starts_with("{$root}/", "{$comparablePath}/")
        ) {
            throw new RuntimeException(Message::NATIVE_INSTALL_DIR_INVALID->getMessage($path));
        }

        return $path;
    }
}
