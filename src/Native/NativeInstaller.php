<?php

declare(strict_types=1);

namespace Foxy\Native;

use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Util\Filesystem;
use FilesystemIterator;
use Foxy\Exception\{Message, RuntimeException};

use function array_is_list;
use function dirname;
use function file_exists;
use function in_array;
use function is_array;
use function is_dir;
use function is_link;
use function is_string;
use function ksort;
use function sort;
use function str_starts_with;
use function strlen;
use function strval;
use function substr;

use const SORT_STRING;

/**
 * Installs the frontend dependencies of a `package.json` into a flat directory (`node_modules` by default) without a
 * Node.js package manager.
 *
 * Registry packages are resolved (or read from a fresh `foxy.lock`), downloaded, and extracted into
 * `<install directory>/.foxy-tmp` before being moved into place; `file:` packages are copied from their local
 * directory. Top-level entries of the install directory outside the install set are removed, except dot-entries such
 * as `.bin`.
 */
final readonly class NativeInstaller implements NativeInstallerInterface
{
    /**
     * Suffix of the temporary directory path that receives each tarball before it moves into place; packages are
     * extracted one at a time.
     */
    private const string EXTRACTION_SUFFIX = '/package';
    private const string FILE_PROTOCOL = 'file:';
    private const string TEMPORARY_DIRECTORY = '.foxy-tmp';

    /**
     * @param IOInterface $io Output that receives one line per installed or removed package.
     * @param Filesystem $fs Filesystem used to copy, move, and remove package directories.
     * @param NpmRegistryInterface $registry Registry that provides the tarballs.
     * @param DependencyResolver $resolver Resolver of the registry requirements.
     * @param TarballExtractor $extractor Extractor of the downloaded tarballs.
     */
    public function __construct(
        private IOInterface $io,
        private Filesystem $fs,
        private NpmRegistryInterface $registry,
        private DependencyResolver $resolver,
        private TarballExtractor $extractor,
    ) {}

    /**
     * Installs the dependencies declared by the manifest, reading or rewriting the lock file.
     *
     * A missing manifest declares no dependency. The lock file is reused when it is fresh (same requirements, same
     * local package versions) and `$update` is `false`; otherwise the requirements are resolved again and the lock
     * file is written before any package is installed, so a download failure leaves a usable lock file.
     *
     * @param string $manifestPath Path of the root `package.json`.
     * @param string $lockPath Path of the `foxy.lock` file.
     * @param string $installDirectory Path of the directory to populate (`node_modules` by default).
     * @param bool $update Whether to resolve again even when the lock file is fresh.
     *
     * @throws RuntimeException if a manifest is invalid, a local package is missing, a requirement cannot be resolved
     * or downloaded, the lock file is malformed, a local package cannot be copied, or an existing package directory
     * cannot be removed.
     */
    public function install(string $manifestPath, string $lockPath, string $installDirectory, bool $update): void
    {
        $manifestFile = new JsonFile($manifestPath);

        $manifest = $manifestFile->exists() ? $manifestFile->read() : [];

        $rootSpecs = $this->specs($manifest, 'dependencies', $manifestPath)
            + $this->specs($manifest, 'devDependencies', $manifestPath);

        ksort($rootSpecs, SORT_STRING);

        $fingerprint = ['' => $rootSpecs];
        $requirements = [];
        $locals = [];

        foreach ($rootSpecs as $name => $spec) {
            $name = strval($name);

            if (!str_starts_with($spec, self::FILE_PROTOCOL)) {
                $requirements[] = new Requirement($name, $spec, '');

                continue;
            }

            $path = substr($spec, strlen(self::FILE_PROTOCOL));
            $local = $this->readLocal($name, $this->localDirectory($manifestPath, $path));

            $fingerprint[$name] = $local['specs'];

            $locals[$name] = ResolvedPackage::fromLocal($name, $local['version'], $path);

            foreach ($local['requirements'] as $requirement) {
                $requirements[] = $requirement;
            }
        }

        $lock = new LockFile($lockPath);

        $packages = $update ? null : $this->lockedPackages($lock, $fingerprint, $locals);

        if (null === $packages) {
            $packages = $locals + $this->resolver->resolve($requirements);

            ksort($packages, SORT_STRING);

            $lock->write($fingerprint, $packages);
        }

        $this->installPackages($manifestPath, $installDirectory, $packages);
    }

    /**
     * Returns the entry names of a directory, sorted byte-wise so the order ignores the locale.
     *
     * @return list<string>
     */
    private static function entries(string $directory): array
    {
        $entries = [];

        foreach (new FilesystemIterator($directory) as $entry) {
            $entries[] = $entry->getFilename();
        }

        sort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * Installs the packages into the install directory and removes the entries outside the install set.
     *
     * @param array<array-key, ResolvedPackage> $packages Packages in installation order.
     *
     * @throws RuntimeException if a download, an extraction, or a local copy fails, or a directory cannot be removed.
     */
    private function installPackages(string $manifestPath, string $installDirectory, array $packages): void
    {
        $temporary = $installDirectory . '/' . self::TEMPORARY_DIRECTORY;

        $this->remove($temporary);
        $this->fs->ensureDirectoryExists($temporary);

        $installed = [];

        foreach ($packages as $package) {
            $target = "{$installDirectory}/{$package->name}";
            $installed[] = $package->name;

            $this->remove($target);

            if (null !== $package->path) {
                $this->io->write(
                    "  - Installing {$package->name} ({$package->version}): Copying from {$package->path}",
                );
                $source = $this->localDirectory($manifestPath, $package->path);

                if (!$this->fs->copy($source, $target)) {
                    throw new RuntimeException(
                        Message::NATIVE_PACKAGE_COPY_FAILED->getMessage($source, $target),
                    );
                }

                continue;
            }

            $this->io->write("  - Installing {$package->name} ({$package->version}): Extracting archive");

            $extracted = $temporary . self::EXTRACTION_SUFFIX;

            $this->extractor->extract(
                $this->registry->fetchTarball($package, $temporary),
                $extracted,
                (string) $package->resolved,
            );
            $this->fs->ensureDirectoryExists(dirname($target));
            $this->fs->rename($extracted, $target);
        }

        $this->prune($installDirectory, $installed);
        $this->remove($temporary);
    }

    private function invalidSpecs(string $field, string $manifestPath): RuntimeException
    {
        return new RuntimeException(
            Message::NATIVE_MANIFEST_DEPENDENCIES_INVALID->getMessage($field, $manifestPath),
        );
    }

    /**
     * Returns the absolute directory of a `file:` value, resolved against the manifest's directory when relative.
     */
    private function localDirectory(string $manifestPath, string $path): string
    {
        return $this->fs->normalizePath(
            $this->fs->isAbsolutePath($path) ? $path : dirname($manifestPath) . '/' . $path,
        );
    }

    /**
     * Returns the locked packages when the lock file is fresh, or `null` when it is missing or stale.
     *
     * @param array<array-key, array<array-key, string>> $fingerprint Current requirements fingerprint.
     * @param array<string, ResolvedPackage> $locals Current local packages keyed by name.
     *
     * @throws RuntimeException if the lock file is malformed.
     *
     * @return array<array-key, ResolvedPackage>|null
     */
    private function lockedPackages(LockFile $lock, array $fingerprint, array $locals): array|null
    {
        if (!$lock->exists()) {
            return null;
        }

        $locked = $lock->read();

        if ($locked['requirements'] !== $fingerprint) {
            return null;
        }

        foreach ($locals as $name => $local) {
            if (($locked['packages'][$name] ?? null)?->version !== $local->version) {
                return null;
            }
        }

        return $locked['packages'];
    }

    /**
     * Removes the top-level install directory entries outside the install set, walking `@scope` directories one level
     * and removing the scopes they empty. Dot-entries are left alone.
     *
     * @param list<string> $installed Installed package names.
     *
     * @throws RuntimeException if an entry cannot be removed.
     */
    private function prune(string $installDirectory, array $installed): void
    {
        foreach (self::entries($installDirectory) as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $path = "{$installDirectory}/{$entry}";

            if (!str_starts_with($entry, '@') || !is_dir($path)) {
                $this->pruneEntry($installDirectory, $entry, $installed);

                continue;
            }

            foreach (self::entries($path) as $child) {
                $this->pruneEntry($installDirectory, "{$entry}/{$child}", $installed);
            }

            if ([] === self::entries($path)) {
                $this->remove($path);
            }
        }
    }

    /**
     * Removes one install directory entry unless it belongs to the install set.
     *
     * @param list<string> $installed Installed package names.
     *
     * @throws RuntimeException if the entry cannot be removed.
     */
    private function pruneEntry(string $installDirectory, string $name, array $installed): void
    {
        if (in_array($name, $installed, true)) {
            return;
        }

        $this->io->write("  - Removing {$name}");
        $this->remove("{$installDirectory}/{$name}");
    }

    /**
     * Returns the version, the requirements fingerprint, and the registry requirements of a local package.
     *
     * @param string $name Local package name, the source of its requirements.
     * @param string $directory Absolute directory of the local package.
     *
     * @throws RuntimeException if the directory has no `package.json`, a dependency map is invalid, or a dependency
     * uses the `file:` protocol.
     *
     * @return array{version: string, specs: array<array-key, string>, requirements: list<Requirement>}
     */
    private function readLocal(string $name, string $directory): array
    {
        $manifestPath = "$directory/package.json";

        $manifestFile = new JsonFile($manifestPath);

        if (!$manifestFile->exists()) {
            throw new RuntimeException(
                Message::NATIVE_LOCAL_PACKAGE_MISSING->getMessage($directory, $name),
            );
        }

        $manifest = $manifestFile->read();

        $version = $manifest['version'] ?? null;
        $meta = $manifest['peerDependenciesMeta'] ?? [];
        $peers = [];

        foreach ($this->specs($manifest, 'peerDependencies', $manifestPath) as $peer => $spec) {
            if (true !== ($meta[$peer]['optional'] ?? null)) {
                $peers[$peer] = $spec;
            }
        }

        $dependencies = $this->specs($manifest, 'dependencies', $manifestPath);
        $optionalDependencies = $this->specs($manifest, 'optionalDependencies', $manifestPath);

        $requirements = [];

        foreach ([[$dependencies, false], [$peers, false], [$optionalDependencies, true]] as [$group, $optional]) {
            foreach ($group as $dependency => $spec) {
                $dependency = strval($dependency);

                if (str_starts_with($spec, self::FILE_PROTOCOL)) {
                    throw new RuntimeException(
                        Message::NATIVE_SPEC_UNSUPPORTED->getMessage($spec, $dependency),
                    );
                }

                $requirements[] = new Requirement($dependency, $spec, $name, $optional);
            }
        }

        $specs = $dependencies + $peers + $optionalDependencies;

        ksort($specs, SORT_STRING);

        return [
            'version' => is_string($version) ? $version : '0.0.0',
            'specs' => $specs,
            'requirements' => $requirements,
        ];
    }

    /**
     * Removes a file or directory when it exists; a symbolic link, dangling or not, is unlinked without following it.
     *
     * @throws RuntimeException if the entry exists and cannot be removed.
     */
    private function remove(string $path): void
    {
        if (is_link($path)) {
            $this->fs->unlink($path);
        } elseif (file_exists($path) && !$this->fs->remove($path)) {
            throw new RuntimeException(
                Message::NATIVE_PACKAGE_REMOVE_FAILED->getMessage($path),
            );
        }
    }

    /**
     * Returns the dependency map of a manifest field, or an empty map when the field is absent.
     *
     * @param mixed $manifest Decoded manifest.
     * @param string $field Field name, such as `dependencies`.
     * @param string $manifestPath Manifest path, used in the error message.
     *
     * @throws RuntimeException if the field does not map non-empty package names to version strings.
     *
     * @return array<array-key, string>
     */
    private function specs(mixed $manifest, string $field, string $manifestPath): array
    {
        $specs = $manifest[$field] ?? [];

        if (!is_array($specs) || ([] !== $specs && array_is_list($specs))) {
            throw $this->invalidSpecs($field, $manifestPath);
        }

        foreach ($specs as $name => $spec) {
            if ('' === $name || !is_string($spec)) {
                throw $this->invalidSpecs($field, $manifestPath);
            }
        }

        return $specs;
    }
}
