<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\Json\JsonFile;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Exception;
use Foxy\Exception\RuntimeException;
use Seld\JsonLint\ParsingException;

use function array_diff_key;
use function count;
use function dirname;
use function is_array;
use function rtrim;
use function str_replace;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;

final class AssetPackage implements AssetPackageInterface
{
    public const string COMPOSER_PREFIX = '@composer-asset/';
    public const string SECTION_DEPENDENCIES = 'dependencies';
    public const string SECTION_DEV_DEPENDENCIES = 'devDependencies';

    private readonly Filesystem $fs;

    private array $package = [];

    /**
     * @param RootPackageInterface $rootPackage The composer root package
     * @param JsonFile $jsonFile The JSON file
     *
     * @throws ParsingException
     */
    public function __construct(
        RootPackageInterface $rootPackage,
        private readonly JsonFile $jsonFile,
        Filesystem|null $fs = null,
    ) {
        $this->fs = $fs ?? new Filesystem();

        if ($jsonFile->exists()) {
            $this->setPackage((array) $jsonFile->read());
        }

        $this->injectRequiredKeys($rootPackage);
    }

    /**
     * @param array $dependencies The dependencies
     *
     * @return list<string> The existing packages.
     */
    public function addNewDependencies(array $dependencies): array
    {
        $installedAssets = $this->getInstalledDependencies();
        $existingPackages = [];

        /**
         * @var string $name
         * @var string $path
         */
        foreach ($dependencies as $name => $path) {
            if (isset($installedAssets[$name])) {
                $existingPackages[] = $name;
            }

            $this->package[self::SECTION_DEPENDENCIES][$name] = $this->normalizeDependencyPath($path);
        }

        $this->orderPackages(self::SECTION_DEPENDENCIES);
        $this->orderPackages(self::SECTION_DEV_DEPENDENCIES);

        return $existingPackages;
    }

    public function getInstalledDependencies(): array
    {
        $installedAssets = [];

        if (isset($this->package[self::SECTION_DEPENDENCIES]) && is_array($this->package[self::SECTION_DEPENDENCIES])) {
            /**
             * @var string $dependency
             * @var string $version
             */
            foreach ($this->package[self::SECTION_DEPENDENCIES] as $dependency => $version) {
                if (str_starts_with($dependency, self::COMPOSER_PREFIX)) {
                    $installedAssets[$dependency] = $version;
                }
            }
        }

        return $installedAssets;
    }

    public function getPackage(): array
    {
        return $this->package;
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function removeUnusedDependencies(array $dependencies): self
    {
        $installedAssets = $this->getInstalledDependencies();
        $removeDependencies = array_diff_key($installedAssets, $dependencies);

        foreach ($removeDependencies as $dependency => $version) {
            unset($this->package[self::SECTION_DEPENDENCIES][$dependency]);
        }

        return $this;
    }

    public function setPackage(array $package): self
    {
        $this->package = $package;

        return $this;
    }

    /**
     * @throws Exception
     */
    public function write(): self
    {
        $this->jsonFile->write($this->package);

        return $this;
    }

    /**
     * Inject the required keys for asset package defined in root composer package.
     *
     * @param RootPackageInterface $rootPackage The composer root package
     */
    private function injectRequiredKeys(RootPackageInterface $rootPackage): void
    {
        if (!isset($this->package['license']) && count($rootPackage->getLicense()) > 0) {
            $license = current($rootPackage->getLicense());

            if ('proprietary' === $license) {
                if (!isset($this->package['private'])) {
                    $this->package['private'] = true;
                }
            } else {
                $this->package['license'] = $license;
            }
        }
    }

    /**
     * Normalize an asset path relative to the package manifest that consumes it.
     */
    private function normalizeDependencyPath(string $path): string
    {
        if (str_starts_with($path, 'file:')) {
            return $path;
        }

        $currentDirectory = getcwd();

        if (false === $currentDirectory) {
            throw new RuntimeException('Unable to get the current working directory.');
        }

        $manifestPath = $this->fs->isAbsolutePath($path)
            ? $path
            : rtrim($currentDirectory, '/\\') . DIRECTORY_SEPARATOR . $path;
        $consumerPath = $this->jsonFile->getPath();
        $consumerPath = $this->fs->isAbsolutePath($consumerPath)
            ? $consumerPath
            : rtrim($currentDirectory, '/\\') . DIRECTORY_SEPARATOR . $consumerPath;
        $relativePath = $this->fs->findShortestPath(
            dirname($consumerPath),
            dirname($manifestPath),
            true,
            true,
        );
        $relativePath = str_replace('\\', '/', $relativePath);

        if (!$this->fs->isAbsolutePath($relativePath) && !str_starts_with($relativePath, '.')) {
            $relativePath = './' . $relativePath;
        }

        return 'file:' . $relativePath;
    }

    /**
     * Order the packages section.
     *
     * @param string $section The package section
     */
    private function orderPackages(string $section): void
    {
        if (isset($this->package[$section]) && is_array($this->package[$section])) {
            ksort($this->package[$section], SORT_STRING);
        }
    }
}
