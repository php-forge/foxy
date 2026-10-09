<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Semver\{Semver, VersionParser};
use Composer\Util\{Filesystem, Platform, ProcessExecutor};
use Exception;
use Foxy\Config\Config;
use Foxy\Converter\{SemverConverter, VersionConverterInterface};
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Fallback\FallbackInterface;
use Foxy\Json\JsonFile;
use Seld\JsonLint\ParsingException;
use Throwable;
use UnexpectedValueException;

use function is_dir;
use function is_string;
use function ltrim;
use function preg_match;
use function rtrim;
use function sprintf;
use function trim;

use const DIRECTORY_SEPARATOR;

abstract class AbstractAssetManager implements AssetManagerInterface
{
    final public const NODE_MODULES_PATH = './node_modules';

    protected bool $updatable = true;

    private string|null $version = '';

    public function __construct(
        protected IOInterface $io,
        protected Config $config,
        protected ProcessExecutor $executor,
        protected Filesystem $fs,
        protected FallbackInterface|null $fallback = null,
        protected VersionConverterInterface|null $versionConverter = null,
    ) {
        $this->versionConverter ??= new SemverConverter();
    }

    /**
     * Get the command to install the asset dependencies.
     */
    abstract protected function getInstallCommand(): string;

    /**
     * Get the command to update the asset dependencies.
     */
    abstract protected function getUpdateCommand(): string;

    /**
     * Get the command to retrieve the version.
     */
    abstract protected function getVersionCommand(): string;

    /**
     * @throws Exception|ParsingException
     */
    public function addDependencies(RootPackageInterface $rootPackage, array $dependencies): AssetPackageInterface
    {
        try {
            $assetPackage = new AssetPackage(
                $rootPackage,
                new JsonFile($this->getPackageJsonPath(), null, $this->io),
            );

            $previousDependencies = $assetPackage->getInstalledDependencies();

            $assetPackage->removeUnusedDependencies($dependencies);

            $alreadyInstalledDependencies = $assetPackage->addNewDependencies($dependencies);

            if ($this->config->isEnabled('run-asset-manager')) {
                $this->actionWhenComposerDependenciesAreAlreadyInstalled($alreadyInstalledDependencies);
            }

            $this->actionWhenComposerDependenciesAreMerged($assetPackage, $previousDependencies);

            $this->io->write('<info>Merging Composer dependencies in the asset package</info>');

            return $assetPackage->write();
        } catch (Throwable $exception) {
            $this->restoreAfterFailure($exception);

            throw $exception;
        }
    }

    public function getPackageJsonPath(): string
    {
        return $this->getRootPackagePath($this->getPackageName());
    }

    public function getPackageName(): string
    {
        return 'package.json';
    }

    public function hasLockFile(): bool
    {
        return file_exists($this->getLockFilePath());
    }

    public function isAvailable(): bool
    {
        $this->config->setResolvedManager($this->getName());

        return null !== $this->getVersion();
    }

    public function isInstalled(): bool
    {
        return is_dir($this->getNodeModulesPath()) && file_exists($this->getPackageJsonPath());
    }

    public function isUpdatable(): bool
    {
        return $this->updatable && $this->isInstalled();
    }

    public function run(): int
    {
        if (!$this->config->isEnabled('run-asset-manager')) {
            return 0;
        }

        $this->validate();

        $managerWorkingDirectory = $this->getManagerWorkingDirectory();
        $updatable = $this->isUpdatable();

        $info = sprintf('<info>%s %s dependencies</info>', $updatable ? 'Updating' : 'Installing', $this->getName());

        $this->io->write($info);

        $res = $this->withManagerTimeout(
            function () use ($updatable, $managerWorkingDirectory): int {
                try {
                    $cmd = $updatable ? $this->getUpdateCommand() : $this->getInstallCommand();

                    return $this->executeManagerCommand($cmd, $managerWorkingDirectory);
                } catch (Throwable $exception) {
                    $this->restoreAfterFailure($exception);

                    throw $exception;
                }
            },
        );

        if (0 !== $res && null !== $this->fallback) {
            $this->restoreAfterFailure(
                new RuntimeException(Message::ASSET_MANAGER_EXITED_WITH_STATUS->getMessage($res), $res),
            );
        }

        return $res;
    }

    public function setFallback(FallbackInterface $fallback): static
    {
        $this->fallback = $fallback;

        return $this;
    }

    public function setUpdatable($updatable): static
    {
        $this->updatable = $updatable;

        return $this;
    }

    public function validate(): void
    {
        $this->config->setResolvedManager($this->getName());

        $version = $this->getVersion();

        if (null === $version) {
            throw new RuntimeException(
                Message::ASSET_MANAGER_BINARY_NOT_INSTALLED->getMessage($this->getName()),
            );
        }

        $supportedVersion = $this->getVersionConstraint();

        $unsupportedVersionMessage = Message::ASSET_VERSION_UNSUPPORTED->getMessage(
            $this->getName(),
            $version,
            $supportedVersion,
        );

        try {
            (new VersionParser())->normalize($version);
        } catch (UnexpectedValueException) {
            throw new RuntimeException(
                $unsupportedVersionMessage,
            );
        }

        if (!Semver::satisfies($version, $supportedVersion)) {
            throw new RuntimeException(
                $unsupportedVersionMessage,
            );
        }

        /** @var string|null $constraintVersion */
        $constraintVersion = $this->config->get('manager-version');

        if (
            is_string($constraintVersion)
            && $constraintVersion !== ''
            && !Semver::satisfies($version, $constraintVersion)
        ) {
            throw new RuntimeException(
                Message::ASSET_VERSION_CONSTRAINT_MISMATCH->getMessage($this->getName(), $version, $constraintVersion),
            );
        }
    }

    /**
     * @param list<string> $names the asset package name of composer dependencies.
     */
    protected function actionWhenComposerDependenciesAreAlreadyInstalled(array $names): void
    {
        // do nothing by default
    }

    /**
     * Runs after the Composer asset dependencies are merged and before the asset package is written.
     *
     * @param AssetPackageInterface $assetPackage The asset package with the merged Composer dependencies.
     * @param array<string, mixed> $previousDependencies The Composer asset dependencies declared before the merge.
     */
    protected function actionWhenComposerDependenciesAreMerged(
        AssetPackageInterface $assetPackage,
        array $previousDependencies,
    ): void {
        // do nothing by default
    }

    /**
     * Build the command with binary and command options.
     *
     * @param string $defaultBin The default binary of command if option isn't defined.
     * @param string $action The command action to retrieve the options in config.
     * @param array|string $command The command.
     */
    protected function buildCommand(string $defaultBin, string $action, array|string $command): string
    {
        $gOptions = trim((string) $this->config->get('manager-options', ''));
        $options = trim((string) $this->config->get("manager-{$action}-options", ''));

        return $this->buildUnconfiguredCommand($defaultBin, $command)
            . ($gOptions === '' ? '' : " {$gOptions}")
            . ($options === '' ? '' : " {$options}");
    }

    /**
     * Build a manager command without inheriting install and update options.
     *
     * @param array|string $command The command.
     */
    protected function buildUnconfiguredCommand(string $defaultBin, array|string $command): string
    {
        return sprintf('%s %s', $this->getManagerBinary($defaultBin), implode(' ', (array) $command));
    }

    protected function getLockFilePath(): string
    {
        return $this->getRootPackagePath($this->getLockPackageName());
    }

    protected function getManagerWorkingDirectory(): string|null
    {
        if (null === $this->getConfiguredRootPackageDir()) {
            return null;
        }

        $rootPackageDir = $this->getRootPackageDir();

        if (!is_dir($rootPackageDir)) {
            throw new RuntimeException(
                Message::ASSET_ROOT_PACKAGE_DIR_MISSING->getMessage($rootPackageDir),
            );
        }

        return $rootPackageDir;
    }

    protected function getNodeModulesPath(): string
    {
        return $this->getRootPackagePath(ltrim(self::NODE_MODULES_PATH, './'));
    }

    protected function getRootPackageDir(): string
    {
        $rootPackageDir = $this->getConfiguredRootPackageDir();

        if (null === $rootPackageDir) {
            return $this->getCurrentDirectory();
        }

        $rootPackageDir = rtrim($rootPackageDir, '/\\');

        if ('' === $rootPackageDir) {
            $rootPackageDir = DIRECTORY_SEPARATOR;
        } elseif (1 === preg_match('/^[A-Za-z]:$/', $rootPackageDir)) {
            $rootPackageDir .= DIRECTORY_SEPARATOR;
        }

        if ($this->isAbsolutePath($rootPackageDir)) {
            return $rootPackageDir;
        }

        return rtrim($this->getCurrentDirectory(), '/\\') . DIRECTORY_SEPARATOR . $rootPackageDir;
    }

    protected function getRootPackagePath(string $path): string
    {
        return rtrim($this->getRootPackageDir(), '/\\') . DIRECTORY_SEPARATOR . $path;
    }

    protected function getVersion(): string|null
    {
        if ($this->version === '' && $this->versionConverter !== null) {
            $this->executor->execute(
                $this->getVersionCommand(),
                $version,
                $this->getManagerWorkingDirectory(),
            );

            $version = $this->normalizeVersionOutput(trim((string) $version));

            $this->version = '' !== $version
                ? $this->versionConverter->convertVersion($version)
                : null;
        }

        return $this->version;
    }

    /**
     * Normalizes the trimmed output of the version command before it is converted.
     */
    protected function normalizeVersionOutput(string $output): string
    {
        return $output;
    }

    /**
     * Runs the callback with the process timeout set to the `manager-timeout` option and restores the previous timeout
     * afterward, even when the callback throws.
     *
     * @template T
     *
     * @param callable(): T $callback The operation to run under the manager timeout.
     *
     * @return T The callback result.
     */
    final protected function withManagerTimeout(callable $callback): mixed
    {
        $timeout = ProcessExecutor::getTimeout();

        /** @var int $managerTimeout */
        $managerTimeout = $this->config->get('manager-timeout', PHP_INT_MAX);

        ProcessExecutor::setTimeout($managerTimeout);

        try {
            return $callback();
        } finally {
            ProcessExecutor::setTimeout($timeout);
        }
    }

    /**
     * Execute a manager command without changing the PHP process working directory.
     */
    private function executeManagerCommand(string $command, string|null $workingDirectory): int
    {
        $outputHandler = function (string $type, string $buffer): void {
            if ('err' === $type) {
                $this->io->writeErrorRaw($buffer, false);

                return;
            }

            $this->io->writeRaw($buffer, false);
        };

        return $this->executor->execute($command, $outputHandler, $workingDirectory);
    }

    /**
     * Returns the configured `root-package-json-dir` value, or `null` when it is unset or empty.
     */
    private function getConfiguredRootPackageDir(): string|null
    {
        $rootPackageDir = $this->config->get('root-package-json-dir');

        return is_string($rootPackageDir) && '' !== $rootPackageDir ? $rootPackageDir : null;
    }

    /**
     * Returns the current working directory of the PHP process.
     *
     * @throws RuntimeException if the current working directory cannot be determined.
     */
    private function getCurrentDirectory(): string
    {
        $currentDir = getcwd();

        if (false === $currentDir) {
            throw new RuntimeException(
                Message::CURRENT_WORKING_DIRECTORY_UNAVAILABLE->getMessage(),
            );
        }

        return $currentDir;
    }

    private function getManagerBinary(string $defaultBin): string
    {
        /** @var string $bin */
        $bin = $this->config->get('manager-bin', $defaultBin);

        return Platform::isWindows() ? str_replace('/', '\\', $bin) : $bin;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ('/' === $path[0] || '\\' === $path[0]) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    /**
     * Restore the asset manifest without hiding the manager failure.
     */
    private function restoreAfterFailure(Throwable $exception): void
    {
        if (null === $this->fallback) {
            return;
        }

        try {
            $this->fallback->restore();
        } catch (Throwable $fallbackException) {
            throw new RuntimeException(
                Message::ASSET_MANAGER_FALLBACK_RESTORE_FAILED->getMessage($fallbackException->getMessage()),
                previous: $exception,
            );
        }
    }
}
