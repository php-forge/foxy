<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Composer\Util\{Filesystem, Platform, ProcessExecutor};
use Exception;
use Foxy\Audit\{AuditProcessResult, AuditableAssetManagerInterface};
use Foxy\Config\Config;
use Foxy\Converter\{SemverConverter, VersionConverterInterface};
use Foxy\Exception\RuntimeException;
use Foxy\Fallback\FallbackInterface;
use Foxy\Json\JsonFile;
use Seld\JsonLint\ParsingException;
use Throwable;
use UnexpectedValueException;

use function array_key_exists;
use function getenv;
use function is_dir;
use function is_string;
use function ltrim;
use function preg_match;
use function putenv;
use function rtrim;
use function sprintf;
use function trim;

use const DIRECTORY_SEPARATOR;

abstract class AbstractAssetManager implements AssetManagerInterface, AuditableAssetManagerInterface
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
     * Get the command to audit the asset dependencies.
     */
    abstract protected function getAuditCommand(bool $noDev): string;

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

            if ($this->config->isEnabled('run-asset-manager')) {
                $this->actionWhenComposerDependenciesAreAlreadyInstalled($alreadyInstalledDependencies);
            }

            $this->io->write('<info>Merging Composer dependencies in the asset package</info>');

            return $assetPackage->write();
        } catch (Throwable $exception) {
            $this->restoreAfterFailure($exception);

            throw $exception;
        }
    }

    public function audit(bool $noDev): AuditProcessResult
    {
        if (!$this->hasLockFile()) {
            throw new RuntimeException(
                sprintf('The %s lock file "%s" was not found.', $this->getName(), $this->getLockFilePath()),
            );
        }

        $this->validateAuditConfiguration($noDev);
        $this->validate();

        $timeout = ProcessExecutor::getTimeout();

        /** @var int $managerTimeout */
        $managerTimeout = $this->config->get('manager-timeout', PHP_INT_MAX);

        ProcessExecutor::setTimeout($managerTimeout);

        $environment = $this->overrideEnvironment($this->getAuditEnvironment());

        try {
            $output = '';
            $result = $this->executor->execute(
                $this->getAuditCommand($noDev),
                $output,
                $this->getManagerWorkingDirectory(),
            );

            return new AuditProcessResult($result, (string) $output, $this->executor->getErrorOutput());
        } finally {
            try {
                $this->restoreEnvironment($environment);
            } finally {
                ProcessExecutor::setTimeout($timeout);
            }
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

        $managerWorkingDirectory = $this->getManagerWorkingDirectory();
        $updatable = $this->isUpdatable();

        $info = sprintf('<info>%s %s dependencies</info>', $updatable ? 'Updating' : 'Installing', $this->getName());

        $this->io->write($info);

        $timeout = ProcessExecutor::getTimeout();

        /** @var int $managerTimeout */
        $managerTimeout = $this->config->get('manager-timeout', PHP_INT_MAX);

        ProcessExecutor::setTimeout($managerTimeout);

        try {
            $cmd = $updatable ? $this->getUpdateCommand() : $this->getInstallCommand();
            $res = $this->executeManagerCommand($cmd, $managerWorkingDirectory);
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
        $gOptions = trim((string) $this->config->get('manager-options', ''));
        $options = trim((string) $this->config->get("manager-{$action}-options", ''));

        return sprintf(
            '%s %s%s%s',
            $this->getManagerBinary($defaultBin),
            implode(' ', (array) $command),
            $gOptions === '' ? '' : " {$gOptions}",
            $options === '' ? '' : " {$options}",
        );
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

    /**
     * Get environment overrides required for a complete audit.
     *
     * @return array<string, string>
     */
    protected function getAuditEnvironment(): array
    {
        return [];
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

    /**
     * Validate manager configuration that can change the audit scope.
     */
    protected function validateAuditConfiguration(bool $noDev): void {}

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

    private function getManagerBinary(string $defaultBin): string
    {
        $bin = (string) $this->config->get('manager-bin', $defaultBin);

        return Platform::isWindows() ? str_replace('/', '\\', $bin) : $bin;
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
     * @param array<string, string> $environment
     *
     * @return array<string, array{
     *     process: string|false,
     *     envExists: bool,
     *     env: mixed,
     *     serverExists: bool,
     *     server: mixed,
     * }>
     */
    private function overrideEnvironment(array $environment): array
    {
        $state = [];

        foreach ($environment as $name => $value) {
            $state[$name] = [
                'process' => getenv($name),
                'envExists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'serverExists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
            ];

            putenv("{$name}={$value}");

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        return $state;
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

    /**
     * @param array<string, array{
     *     process: string|false,
     *     envExists: bool,
     *     env: mixed,
     *     serverExists: bool,
     *     server: mixed,
     * }> $state
     */
    private function restoreEnvironment(array $state): void
    {
        foreach ($state as $name => $values) {
            putenv(false === $values['process'] ? $name : "{$name}=" . $values['process']);

            if ($values['envExists']) {
                $_ENV[$name] = $values['env'];
            } else {
                unset($_ENV[$name]);
            }

            if ($values['serverExists']) {
                $_SERVER[$name] = $values['server'];
            } else {
                unset($_SERVER[$name]);
            }
        }
    }
}
