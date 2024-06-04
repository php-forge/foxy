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

namespace Foxy\Asset;

use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Semver\VersionParser;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Foxy\Config\Config;
use Foxy\Converter\SemverConverter;
use Foxy\Converter\VersionConverterInterface;
use Foxy\Exception\RuntimeException;
use Foxy\Fallback\FallbackInterface;
use Foxy\Json\JsonFile;

/**
 * Abstract Manager.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
abstract class AbstractAssetManager implements AssetManagerInterface
{
    final public const NODE_MODULES_PATH = './node_modules';
    protected bool $updatable = true;
    private null|string $version = '';

    public function __construct(
        protected IOInterface $io,
        protected Config $config,
        protected ProcessExecutor $executor,
        protected Filesystem $fs,
        protected FallbackInterface|null $fallback = null,
        protected VersionConverterInterface|null $versionConverter = null
    ) {
        $this->versionConverter ??= new SemverConverter();
    }

    public function isAvailable(): bool
    {
        return null !== $this->getVersion();
    }

    public function getPackageName(): string
    {
        return 'package.json';
    }

    public function hasLockFile(): bool
    {
        return file_exists($this->getLockPackageName());
    }

    public function isInstalled(): bool
    {
        return is_dir(self::NODE_MODULES_PATH) && file_exists($this->getPackageName());
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

    public function isUpdatable(): bool
    {
        return $this->updatable && $this->isInstalled() && $this->isValidForUpdate();
    }

    public function isValidForUpdate(): bool
    {
        return true;
    }

    public function validate(): void
    {
        $version = $this->getVersion();
        /** @var string $constraintVersion */
        $constraintVersion = $this->config->get('manager-version');

        if (null === $version) {
            throw new RuntimeException(sprintf('The binary of "%s" must be installed', $this->getName()));
        }

        if ($constraintVersion) {
            $parser = new VersionParser();
            $constraint = $parser->parseConstraints($constraintVersion);

            if (!$constraint->matches($parser->parseConstraints($version))) {
                throw new RuntimeException(
                    sprintf(
                        'The installed %s version "%s" doesn\'t match with the constraint version "%s"',
                        $this->getName(),
                        $version,
                        $constraintVersion
                    )
                );
            }
        }
    }

    public function addDependencies(RootPackageInterface $rootPackage, array $dependencies): AssetPackageInterface
    {
        $assetPackage = new AssetPackage($rootPackage, new JsonFile($this->getPackageName(), null, $this->io));
        $assetPackage->removeUnusedDependencies($dependencies);
        $alreadyInstalledDependencies = $assetPackage->addNewDependencies($dependencies);

        $this->actionWhenComposerDependenciesAreAlreadyInstalled($alreadyInstalledDependencies);
        $this->io->write('<info>Merging Composer dependencies in the asset package</info>');

        return $assetPackage->write();
    }

    public function run(): int
    {
        if (true !== $this->config->get('run-asset-manager')) {
            return 0;
        }

        $rootPackageDir = $this->config->get('root-package-dir');

        if (\is_string($rootPackageDir) && '' !== $rootPackageDir && \is_dir($rootPackageDir)) {
            \chdir($rootPackageDir);
        }

        if (\is_string($rootPackageDir) && '' !== $rootPackageDir && \is_dir($rootPackageDir) === false) {
            throw new RuntimeException(\sprintf('The root package directory "%s" doesn\'t exist.', $rootPackageDir));
        }

        $updatable = $this->isUpdatable();
        $info = sprintf('<info>%s %s dependencies</info>', $updatable ? 'Updating' : 'Installing', $this->getName());
        $this->io->write($info);

        $timeout = ProcessExecutor::getTimeout();

        /** @var int $managerTimeout */
        $managerTimeout = $this->config->get('manager-timeout', PHP_INT_MAX);
        ProcessExecutor::setTimeout($managerTimeout);

        $cmd = $updatable ? $this->getUpdateCommand() : $this->getInstallCommand();
        $res = $this->executor->execute($cmd);

        ProcessExecutor::setTimeout($timeout);

        if ($res > 0 && null !== $this->fallback) {
            $this->fallback->restore();
        }

        return $res;
    }

    /**
     * Action when the composer dependencies are already installed.
     *
     * @param array $names the asset package name of composer dependencies.
     *
     * @psalm-param list<string> $names
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

        /** @psalm-var string|string[] $command */
        return (string) $bin . ' ' . implode(' ', (array) $command)
            . (empty($gOptions) ? '' : ' ' . $gOptions)
            . (empty($options) ? '' : ' ' . $options);
    }

    protected function getVersion(): string|null
    {
        if ($this->version === '' && $this->versionConverter !== null) {
            $this->executor->execute($this->getVersionCommand(), $version);
            $this->version = '' !== trim((string) $version) ? $this->versionConverter->convertVersion(trim((string) $version)) : null;
        }

        return $this->version;
    }

    /**
     * Get the command to retrieve the version.
     */
    abstract protected function getVersionCommand(): string;

    /**
     * Get the command to install the asset dependencies.
     */
    abstract protected function getInstallCommand(): string;

    /**
     * Get the command to update the asset dependencies.
     */
    abstract protected function getUpdateCommand(): string;
}
