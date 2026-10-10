<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Composer\IO\IOInterface;
use Composer\Semver\{Semver, VersionParser};
use Composer\Util\{Filesystem, Platform, ProcessExecutor};
use Foxy\Config\Config;
use Foxy\Converter\{SemverConverter, VersionConverterInterface};
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Fallback\FallbackInterface;
use Throwable;
use UnexpectedValueException;

use function is_string;
use function sprintf;
use function trim;

abstract class AbstractAssetManager extends AbstractManifestAssetManager
{
    private string|null $version = '';

    public function __construct(
        IOInterface $io,
        Config $config,
        protected ProcessExecutor $executor,
        Filesystem $fs,
        FallbackInterface|null $fallback = null,
        protected VersionConverterInterface|null $versionConverter = null,
    ) {
        parent::__construct($io, $config, $fs, $fallback);

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

    public function isAvailable(): bool
    {
        $this->config->setResolvedManager($this->getName());

        return null !== $this->getVersion();
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
                new RuntimeException(
                    Message::ASSET_MANAGER_EXITED_WITH_STATUS->getMessage($res),
                    $res,
                ),
            );
        }

        return $res;
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

    private function getManagerBinary(string $defaultBin): string
    {
        /** @var string $bin */
        $bin = $this->config->get('manager-bin', $defaultBin);

        return Platform::isWindows() ? str_replace('/', '\\', $bin) : $bin;
    }
}
