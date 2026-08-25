<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Composer\Util\{Filesystem, Platform, ProcessExecutor};
use Exception;
use Foxy\Config\Config;
use Foxy\Converter\{SemverConverter, VersionConverterInterface};
use Foxy\Exception\RuntimeException;
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

            $assetPackage->removeUnusedDependencies($dependencies);

            $alreadyInstalledDependencies = $assetPackage->addNewDependencies($dependencies);

            $this->actionWhenComposerDependenciesAreAlreadyInstalled($alreadyInstalledDependencies);
            $this->io->write('<info>Merging Composer dependencies in the asset package</info>');

            return $assetPackage->write();
        } catch (Throwable $exception) {
            $this->restoreAfterFailure($exception);

            throw $exception;
        }
    }

    public function getPackageJsonPath(): string
    {
        return rtrim($this->getRootPackageDir(), '/\\') . DIRECTORY_SEPARATOR . $this->getPackageName();
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

        $rootPackageDir = $this->getManagerWorkingDirectory();

        $originalDir = null;
        $changedDir = false;

        if (null !== $rootPackageDir) {
            $originalDir = getcwd();

            if (false === $originalDir) {
                throw new RuntimeException('Unable to get the current working directory.');
            }

            if (chdir($rootPackageDir) === false) {
                throw new RuntimeException(sprintf('Unable to change working directory to "%s".', $rootPackageDir));
            }

            $changedDir = true;
        }

        try {
            $updatable = $this->isUpdatable();

            $info = sprintf('<info>%s %s dependencies</info>', $updatable ? 'Updating' : 'Installing', $this->getName());

            $this->io->write($info);

            $timeout = ProcessExecutor::getTimeout();

            /** @var int $managerTimeout */
            $managerTimeout = $this->config->get('manager-timeout', PHP_INT_MAX);

            ProcessExecutor::setTimeout($managerTimeout);

            try {
                $cmd = $updatable ? $this->getUpdateCommand() : $this->getInstallCommand();
                $res = $this->executor->execute($cmd);
            } catch (Throwable $exception) {
                $this->restoreAfterFailure($exception);

                throw $exception;
            } finally {
                ProcessExecutor::setTimeout($timeout);
            }

            if (0 !== $res && null !== $this->fallback) {
                $this->restoreAfterFailure(
                    new RuntimeException(sprintf('The asset manager exited with status code %d.', $res), $res),
                );
            }
        } finally {
            if ($changedDir && chdir($originalDir) === false) {
                throw new RuntimeException(
                    sprintf('Unable to restore working directory to "%s".', $originalDir),
                );
            }
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
        $version = $this->getVersion();

        if (null === $version) {
            throw new RuntimeException(sprintf('The binary of "%s" must be installed', $this->getName()));
        }

        $parser = new VersionParser();

        $supportedVersion = $this->getVersionConstraint();

        $unsupportedVersionMessage = sprintf(
            'The installed %s version "%s" doesn\'t match with the supported version constraint "%s"',
            $this->getName(),
            $version,
            $supportedVersion,
        );

        try {
            $versionConstraint = new Constraint('==', $parser->normalize($version));
        } catch (UnexpectedValueException) {
            throw new RuntimeException($unsupportedVersionMessage);
        }

        if (!$parser->parseConstraints($supportedVersion)->matches($versionConstraint)) {
            throw new RuntimeException($unsupportedVersionMessage);
        }

        /** @var string|null $constraintVersion */
        $constraintVersion = $this->config->get('manager-version');

        if (is_string($constraintVersion) && $constraintVersion !== '') {
            $constraint = $parser->parseConstraints($constraintVersion);

            if (!$constraint->matches($versionConstraint)) {
                throw new RuntimeException(
                    sprintf(
                        'The installed %s version "%s" doesn\'t match with the constraint version "%s"',
                        $this->getName(),
                        $version,
                        $constraintVersion,
                    ),
                );
            }
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
     * Build the command with binary and command options.
     *
     * @param string $defaultBin The default binary of command if option isn't defined.
     * @param string $action The command action to retrieve the options in config.
     * @param array|string $command The command.
     */
    protected function buildCommand(string $defaultBin, string $action, array|string $command): string
    {
        $bin = $this->config->get('manager-bin', $defaultBin);

        $bin = Platform::isWindows() ? str_replace('/', '\\', (string) $bin) : $bin;

        $gOptions = trim((string) $this->config->get('manager-options', ''));
        $options = trim((string) $this->config->get('manager-' . $action . '-options', ''));

        return sprintf(
            '%s %s%s%s',
            $bin,
            implode(' ', (array) $command),
            $gOptions === '' ? '' : ' ' . $gOptions,
            $options === '' ? '' : ' ' . $options,
        );
    }

    protected function getLockFilePath(): string
    {
        return $this->getRootPackagePath($this->getLockPackageName());
    }

    protected function getNodeModulesPath(): string
    {
        return $this->getRootPackagePath(ltrim(self::NODE_MODULES_PATH, './'));
    }

    protected function getRootPackageDir(): string
    {
        $rootPackageDir = $this->config->get('root-package-json-dir');

        if (is_string($rootPackageDir) && '' !== $rootPackageDir) {
            $rootPackageDir = rtrim($rootPackageDir, '/\\');

            if ('' === $rootPackageDir) {
                $rootPackageDir = DIRECTORY_SEPARATOR;
            } elseif (1 === preg_match('/^[A-Za-z]:$/', $rootPackageDir)) {
                $rootPackageDir .= DIRECTORY_SEPARATOR;
            }

            if (!$this->isAbsolutePath($rootPackageDir)) {
                $currentDir = getcwd();

                if (false === $currentDir) {
                    throw new RuntimeException('Unable to get the current working directory.');
                }

                $rootPackageDir = rtrim($currentDir, '/\\') . DIRECTORY_SEPARATOR . $rootPackageDir;
            }

            return $rootPackageDir;
        }

        $currentDir = getcwd();

        if (false === $currentDir) {
            throw new RuntimeException('Unable to get the current working directory.');
        }

        return $currentDir;
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

            $version = trim((string) $version);

            $this->version = '' !== $version
                ? $this->versionConverter->convertVersion($version)
                : null;
        }

        return $this->version;
    }

    private function getManagerWorkingDirectory(): string|null
    {
        $rootPackageDir = $this->config->get('root-package-json-dir');

        if (!is_string($rootPackageDir) || $rootPackageDir === '') {
            return null;
        }

        $rootPackageDir = $this->getRootPackageDir();

        if (!is_dir($rootPackageDir)) {
            throw new RuntimeException(sprintf('The root package directory "%s" doesn\'t exist.', $rootPackageDir));
        }

        return $rootPackageDir;
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
                sprintf(
                    'The asset manager failed and its fallback could not be restored: %s',
                    $fallbackException->getMessage(),
                ),
                previous: $exception,
            );
        }
    }
}
