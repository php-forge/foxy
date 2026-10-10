<?php

declare(strict_types=1);

namespace Foxy\Native;

use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Util\{Filesystem, Platform};
use FilesystemIterator;
use Foxy\Exception\{Message, RuntimeException};

use function array_diff_key;
use function array_is_list;
use function basename;
use function dirname;
use function file_exists;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function ksort;
use function sort;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

use const SORT_STRING;

/**
 * Installs the frontend dependencies of a `package.json` into a flat directory (`node_modules` by default) without a
 * Node.js package manager.
 *
 * Registry packages are resolved (or read from a fresh `foxy.lock`), downloaded, and extracted into
 * `<install directory>/.foxy-tmp` before being moved into place; `file:` packages are copied from their local
 * directory. Top-level entries of the install directory outside the install set are removed, except dot-entries such
 * as `.bin`. A registry package already in place with its recorded lock entry is kept; see {@see InstalledState}.
 *
 * @phpstan-import-type Fingerprint from LockFile
 */
final readonly class NativeInstaller implements NativeInstallerInterface
{
    /**
     * Suffix of the temporary directory path that receives each tarball before it moves into place; packages are
     * extracted one at a time.
     */
    private const string EXTRACTION_SUFFIX = '/package';
    private const string FILE_PROTOCOL = 'file:';
    private const string OPTIONAL_SKIPPED = '<warning>The optional dependency "%s" (%s) was skipped: %s</warning>';
    /**
     * Name of the installed state file inside the install directory; see {@see InstalledState}.
     */
    private const string STATE_FILE = '.foxy-installed.json';
    private const string TEMPORARY_DIRECTORY = '.foxy-tmp';

    /**
     * @param IOInterface $io Output that receives one line per changed package, and per kept package at verbose level.
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
     * A missing manifest declares no dependency. An optional `file:` package whose `package.json` is missing is skipped
     * with a warning. The `dependencies`, `devDependencies`, and `optionalDependencies` maps
     * are merged, an optional entry overriding a mandatory one of the same name as npm does; an unsatisfiable optional
     * entry is skipped with a warning. The lock file is reused when it is fresh (same requirements, same
     * local package versions) and `$update` is `false`; otherwise the requirements are resolved again and the lock
     * file is written before any package is installed, so a download failure leaves a usable lock file.
     *
     * @param string $manifestPath Path of the root `package.json`.
     * @param string $lockPath Path of the `foxy.lock` file.
     * @param string $installDirectory Path of the directory to populate (`node_modules` by default).
     * @param bool $update Whether to resolve again even when the lock file is fresh.
     *
     * @throws RuntimeException if a manifest is invalid, a mandatory local package is missing, a local package overlaps
     * the install directory, a requirement cannot be resolved or downloaded, the lock file is malformed, a local package
     * cannot be copied, or an existing package directory cannot be removed.
     */
    public function install(string $manifestPath, string $lockPath, string $installDirectory, bool $update): void
    {
        $manifestFile = new JsonFile($manifestPath);

        $manifest = $manifestFile->exists() ? $manifestFile->read() : [];

        $optionalSpecs = $this->specs($manifest, 'optionalDependencies', $manifestPath);
        $mandatorySpecs = array_diff_key(
            $this->specs($manifest, 'dependencies', $manifestPath)
            + $this->specs($manifest, 'devDependencies', $manifestPath),
            $optionalSpecs,
        );

        ksort($mandatorySpecs, SORT_STRING);
        ksort($optionalSpecs, SORT_STRING);

        $fingerprint = ['' => ['dependencies' => $mandatorySpecs, 'optionalDependencies' => $optionalSpecs]];
        $requirements = [];
        $locals = [];
        $sources = [];

        foreach ([[$mandatorySpecs, false], [$optionalSpecs, true]] as [$specs, $optional]) {
            foreach ($specs as $name => $spec) {
                $name = (string) $name;

                if (!str_starts_with($spec, self::FILE_PROTOCOL)) {
                    $requirements[] = new Requirement($name, $spec, '', $optional);

                    continue;
                }

                $path = substr($spec, strlen(self::FILE_PROTOCOL));
                $directory = $this->localDirectory($manifestPath, $path);

                if (is_dir($directory)) {
                    $sources[$name] = $directory;
                }

                if ($optional && !is_file("{$directory}/package.json")) {
                    $this->io->writeError(
                        sprintf(
                            self::OPTIONAL_SKIPPED,
                            $name,
                            $spec,
                            Message::NATIVE_LOCAL_PACKAGE_MISSING->getMessage($directory, $name),
                        ),
                    );

                    continue;
                }

                $local = $this->readLocal($name, $directory);

                $fingerprint[$name] = $local['specs'] + ['version' => $local['version']];

                $locals[$name] = new LocalPackage($name, $local['version'], $path, $local['requirements']);
                $requirements[] = new Requirement($name, $spec, '', $optional);
            }
        }

        ksort($fingerprint, SORT_STRING);

        $this->checkOverlaps($installDirectory, $sources);

        $lock = new LockFile($lockPath);

        $packages = $update ? null : $this->lockedPackages($lock, $fingerprint);

        if (null === $packages) {
            $packages = $this->resolver->resolve($requirements, $locals);

            $lock->write($fingerprint, $packages);
        }

        $this->installPackages($manifestPath, $installDirectory, $packages);
    }

    /**
     * Checks that no local package directory is the install directory, lies under it, or contains it, since the
     * installer removes and replaces the entries of the install directory.
     *
     * Every existing `file:` directory of the root manifest is checked, including an optional one skipped for lacking
     * a `package.json`. Both directories are compared after resolving symbolic links as far as PHP's `realpath()`
     * resolves them; the install directory is created first so it can be resolved.
     *
     * @param array<string, string> $sources Existing local package directories keyed by package name.
     *
     * @throws RuntimeException if a local package directory overlaps the install directory.
     */
    private function checkOverlaps(string $installDirectory, array $sources): void
    {
        if ([] === $sources) {
            return;
        }

        $this->fs->ensureDirectoryExists($installDirectory);

        $install = $this->fs->normalizePath(Platform::realpath($installDirectory)) . '/';

        foreach ($sources as $name => $directory) {
            $source = $this->fs->normalizePath(Platform::realpath($directory)) . '/';

            if (str_starts_with($source, $install) || str_starts_with($install, $source)) {
                throw new RuntimeException(
                    Message::NATIVE_INSTALL_DIR_OVERLAPS_LOCAL->getMessage($installDirectory, $name, $directory),
                );
            }
        }
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
     * Installs the packages into the install directory, removes the entries outside the install set, and records the
     * install set in the installed state file.
     *
     * A registry package whose directory exists and whose lock entry equals the recorded one is kept as is; every other
     * registry package is extracted again, and every `file:` package is copied again since its content may change
     * without a version change. The state file is removed before any change and written after the pruning, so an
     * interrupted installation leaves no state and the next one extracts every registry package.
     *
     * The tarballs of the registry packages to extract are first prefetched in parallel into the registry's cache; each
     * package is then fetched, verified, and extracted in turn, so a failed prefetch surfaces as the error of its own
     * fetch.
     *
     * A symbolic link or a file standing in for the `@scope` directory of a package is removed first, so the package is
     * never written into the link target and a stale file cannot abort the installation.
     *
     * Changes are written at normal verbosity, or `Nothing to install, update or remove` when there is none; kept
     * packages and refreshed local copies are written at verbose level.
     *
     * @param array<array-key, ResolvedPackage> $packages Packages in installation order.
     *
     * @throws RuntimeException if a download, an extraction, or a local copy fails, or a directory cannot be removed.
     */
    private function installPackages(string $manifestPath, string $installDirectory, array $packages): void
    {
        $temporary = "{$installDirectory}/" . self::TEMPORARY_DIRECTORY;
        $statePath = "{$installDirectory}/" . self::STATE_FILE;

        $stateFile = new InstalledState($statePath, $this->io);

        $state = $stateFile->read();

        $this->remove($statePath);
        $this->remove($temporary);
        $this->fs->ensureDirectoryExists($temporary);

        $changed = false;
        $entries = [];
        $installed = [];
        $downloads = [];
        $kept = [];

        foreach ($packages as $package) {
            $target = "{$installDirectory}/{$package->name}";
            $scope = dirname($target);
            $entry = $package->toLock();
            $installed[] = $package->name;
            $entries[$package->name] = $entry;

            if ($scope !== $installDirectory && (is_link($scope) || (file_exists($scope) && !is_dir($scope)))) {
                $this->io->write('  - Removing ' . basename($scope));
                $this->remove($scope);

                $changed = true;
            }

            if ($package->isLocal()) {
                continue;
            }

            if (($state[$package->name] ?? null) === $entry && is_dir($target) && !is_link($target)) {
                $kept[$package->name] = true;

                continue;
            }

            $downloads[] = $package;
        }

        if ([] !== $downloads) {
            $this->registry->prefetchTarballs($downloads, $temporary);
        }

        foreach ($packages as $package) {
            $target = "{$installDirectory}/{$package->name}";
            $recorded = $state[$package->name] ?? null;
            $previous = $recorded['version'] ?? $package->version;
            $label = $previous === $package->version
                ? "  - Installing {$package->name} ({$package->version})"
                : "  - Updating {$package->name} ({$previous} => {$package->version})";

            if (isset($kept[$package->name])) {
                $this->io->write("  - Keeping {$package->name} ({$package->version})", true, IOInterface::VERBOSE);

                continue;
            }

            $this->remove($target);

            if (null !== $package->path) {
                if ($recorded === $entries[$package->name]) {
                    $this->io->write(
                        "  - Refreshing {$package->name} ({$package->version}): Copying from {$package->path}",
                        true,
                        IOInterface::VERBOSE,
                    );
                } else {
                    $this->io->write("{$label}: Copying from {$package->path}");

                    $changed = true;
                }

                $source = $this->localDirectory($manifestPath, $package->path);

                if (!$this->fs->copy($source, $target)) {
                    throw new RuntimeException(
                        Message::NATIVE_PACKAGE_COPY_FAILED->getMessage($source, $target),
                    );
                }

                continue;
            }

            $this->io->write("{$label}: Extracting archive");

            $changed = true;
            $extracted = $temporary . self::EXTRACTION_SUFFIX;

            $this->extractor->extract(
                $this->registry->fetchTarball($package, $temporary),
                $extracted,
                (string) $package->resolved,
            );
            $this->fs->ensureDirectoryExists(dirname($target));
            $this->fs->rename($extracted, $target);
        }

        $changed = $this->prune($installDirectory, $installed) || $changed;

        $this->remove($temporary);
        $stateFile->write($entries);

        if (!$changed) {
            $this->io->write('Nothing to install, update or remove');
        }
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
     * The lock is fresh when its fingerprint equals the current one, which covers the requirements of every source and
     * the version of every local package.
     *
     * @param Fingerprint $fingerprint Current requirements fingerprint.
     *
     * @throws RuntimeException if the lock file is malformed.
     *
     * @return array<array-key, ResolvedPackage>|null
     */
    private function lockedPackages(LockFile $lock, array $fingerprint): array|null
    {
        if (!$lock->exists()) {
            return null;
        }

        $locked = $lock->read();

        return $locked['requirements'] === $fingerprint ? $locked['packages'] : null;
    }

    /**
     * Removes the top-level install directory entries outside the install set, walking `@scope` directories one level
     * and removing the scopes they empty. Dot-entries are left alone, and a symbolic link is removed as one entry
     * without visiting its target.
     *
     * @param list<string> $installed Installed package names.
     *
     * @throws RuntimeException if an entry cannot be removed.
     *
     * @return bool Whether a package entry was removed.
     */
    private function prune(string $installDirectory, array $installed): bool
    {
        $removed = false;

        foreach (self::entries($installDirectory) as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $path = "{$installDirectory}/{$entry}";

            if (!str_starts_with($entry, '@') || is_link($path) || !is_dir($path)) {
                $removed = $this->pruneEntry($installDirectory, $entry, $installed) || $removed;

                continue;
            }

            foreach (self::entries($path) as $child) {
                $removed = $this->pruneEntry($installDirectory, "{$entry}/{$child}", $installed) || $removed;
            }

            if ([] === self::entries($path)) {
                $this->remove($path);
            }
        }

        return $removed;
    }

    /**
     * Removes one install directory entry unless it belongs to the install set.
     *
     * @param list<string> $installed Installed package names.
     *
     * @throws RuntimeException if the entry cannot be removed.
     *
     * @return bool Whether the entry was removed.
     */
    private function pruneEntry(string $installDirectory, string $name, array $installed): bool
    {
        if (in_array($name, $installed, true)) {
            return false;
        }

        $this->io->write("  - Removing {$name}");
        $this->remove("{$installDirectory}/{$name}");

        return true;
    }

    /**
     * Returns the version, the requirements fingerprint, and the registry requirements of a local package.
     *
     * An optional dependency overrides a dependency or a non-optional peer of the same name, as npm does.
     *
     * @param string $name Local package name, the source of its requirements.
     * @param string $directory Absolute directory of the local package.
     *
     * @throws RuntimeException if the directory has no `package.json`, a dependency map is invalid, or a dependency
     * uses the `file:` protocol.
     *
     * @return array{
     *     version: string,
     *     specs: array{dependencies: array<array-key, string>, optionalDependencies: array<array-key, string>},
     *     requirements: list<Requirement>,
     * }
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
        $optionalDependencies = $this->specs($manifest, 'optionalDependencies', $manifestPath);
        $peers = [];

        foreach ($this->specs($manifest, 'peerDependencies', $manifestPath) as $peer => $spec) {
            if (true !== ($meta[$peer]['optional'] ?? null) && !isset($optionalDependencies[$peer])) {
                $peers[$peer] = $spec;
            }
        }

        $dependencies = array_diff_key($this->specs($manifest, 'dependencies', $manifestPath), $optionalDependencies);

        $requirements = [];

        foreach ([[$dependencies, false], [$peers, false], [$optionalDependencies, true]] as [$group, $optional]) {
            foreach ($group as $dependency => $spec) {
                $dependency = (string) $dependency;

                if (str_starts_with($spec, self::FILE_PROTOCOL)) {
                    throw new RuntimeException(
                        Message::NATIVE_SPEC_UNSUPPORTED->getMessage($spec, $dependency),
                    );
                }

                $requirements[] = new Requirement($dependency, $spec, $name, $optional);
            }
        }

        $mandatory = $dependencies + $peers;

        ksort($mandatory, SORT_STRING);
        ksort($optionalDependencies, SORT_STRING);

        return [
            'version' => is_string($version) ? $version : '0.0.0',
            'specs' => ['dependencies' => $mandatory, 'optionalDependencies' => $optionalDependencies],
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
     * @throws RuntimeException if the field does not map valid package names to version strings.
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
            if (!PackageName::isValid((string) $name) || !is_string($spec)) {
                throw $this->invalidSpecs($field, $manifestPath);
            }
        }

        return $specs;
    }
}
