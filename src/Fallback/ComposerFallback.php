<?php

declare(strict_types=1);

namespace Foxy\Fallback;

use Composer\{Composer, Factory};
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Installer;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Exception;
use Foxy\Config\Config;
use Foxy\Exception\RuntimeException;
use Foxy\Util\{ConsoleUtil, LockerUtil, PackageUtil};
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

use function is_link;
use function sprintf;

final class ComposerFallback implements FallbackInterface
{
    private readonly Filesystem $fs;

    private array|null $hydratedLock = null;

    private array $lock = [];

    private string|null $lockFile = null;

    private bool $lockFileExisted = false;

    private bool $snapshotSaved = false;

    private string|null $vendorDir = null;

    private bool $vendorDirExisted = false;

    /**
     * @param Composer $composer The composer.
     * @param IOInterface $io The IO.
     * @param Config $config The config.
     * @param InputInterface $input The input.
     * @param Filesystem|null $fs The composer filesystem.
     * @param Installer|null $installer The installer.
     */
    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
        private readonly Config $config,
        private readonly InputInterface $input,
        Filesystem|null $fs = null,
        private readonly Installer|null $installer = null,
    ) {
        $this->fs = $fs ?? new Filesystem();
    }

    /**
     * @throws Exception
     */
    public function restore(): void
    {
        if (!$this->isEnabled() || !$this->snapshotSaved) {
            return;
        }

        $this->io->write('<info>Fallback to previous state for Composer</info>');

        if (!$this->lockFileExisted) {
            $this->removeCreatedState();

            return;
        }

        $this->restoreLockData();
        $this->restorePreviousLockFile();
    }

    public function save(): self
    {
        $this->resetSnapshot();

        if (!$this->isEnabled()) {
            return $this;
        }

        $vendorDir = $this->composer->getConfig()->get('vendor-dir');

        $this->vendorDir = '' !== $vendorDir ? $vendorDir : null;

        $this->vendorDirExisted = null !== $this->vendorDir && file_exists($this->vendorDir);

        $im = $this->composer->getInstallationManager();

        $composerFile = Factory::getComposerFile();
        $locker = LockerUtil::getLocker($this->io, $im, $composerFile);

        $lockFile = $locker->getJsonFile();

        $this->lockFile = $lockFile->getPath();
        $this->lockFileExisted = $lockFile->exists();

        if ($this->lockFileExisted) {
            $this->lock = $locker->getLockData();
        }

        $this->snapshotSaved = true;

        return $this;
    }

    private function getHydratedLock(): array
    {
        return $this->hydratedLock ??= PackageUtil::loadLockPackages($this->lock, false);
    }

    private function getInstaller(): Installer
    {
        return $this->installer ?? Installer::create($this->io, $this->composer);
    }

    private function isEnabled(): bool
    {
        $fallbackComposer = $this->config->get('fallback-composer');

        return $fallbackComposer === true || $fallbackComposer === 1 || $fallbackComposer === '1';
    }

    private function pathExists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    private function removeCreatedPath(string $path): void
    {
        try {
            $removed = $this->fs->remove($path);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Unable to remove Composer fallback path "%s".', $path),
                0,
                $exception,
            );
        }

        if (!$removed && $this->pathExists($path)) {
            throw new RuntimeException(
                sprintf('Unable to remove Composer fallback path "%s".', $path),
            );
        }
    }

    private function removeCreatedState(): void
    {
        if (null !== $this->lockFile && $this->pathExists($this->lockFile)) {
            $this->removeCreatedPath($this->lockFile);
        }

        if (
            !$this->vendorDirExisted
            && null !== $this->vendorDir
            && $this->pathExists($this->vendorDir)
        ) {
            $this->removeCreatedPath($this->vendorDir);
        }
    }

    private function resetSnapshot(): void
    {
        $this->hydratedLock = null;
        $this->snapshotSaved = false;
    }

    /**
     * Restore the data of lock file.
     */
    private function restoreLockData(): void
    {
        $lock = $this->getHydratedLock();

        /** @psalm-suppress MixedArgument */
        $this->composer->getLocker()->setLockData(
            $lock['packages'] ?? [],
            $lock['packages-dev'] ?? null,
            $lock['platform'] ?? [],
            $lock['platform-dev'] ?? [],
            $lock['aliases'] ?? [],
            $lock['minimum-stability'] ?? '',
            $lock['stability-flags'] ?? [],
            $lock['prefer-stable'] ?? false,
            $lock['prefer-lowest'] ?? false,
            $lock['platform-overrides'] ?? [],
        );
    }

    /**
     * Restore the PHP dependencies with the previous lock file.
     *
     * @throws Exception
     */
    private function restorePreviousLockFile(): void
    {
        $config = $this->composer->getConfig();

        [$preferSource, $preferDist] = ConsoleUtil::getPreferredInstallOptions($config, $this->input);

        $isOptionTrue = (static fn(mixed $value): bool => $value === true || $value === 1 || $value === '1');

        $optimize = $isOptionTrue($this->input->getOption('optimize-autoloader'))
            || $isOptionTrue($config->get('optimize-autoloader'));
        $authoritative = $isOptionTrue($this->input->getOption('classmap-authoritative'))
            || $isOptionTrue($config->get('classmap-authoritative'));
        $apcu = $isOptionTrue($this->input->getOption('apcu-autoloader'))
            || $isOptionTrue($config->get('apcu-autoloader'));

        $verbose = (bool) $this->input->getOption('verbose');
        $devMode = $isOptionTrue($this->input->getOption('no-dev')) === false;
        $dumpAutoloader = $isOptionTrue($this->input->getOption('no-autoloader')) === false;

        $installer = $this->getInstaller()
            ->setVerbose($verbose)
            ->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setDevMode($devMode)
            ->setDumpAutoloader($dumpAutoloader)
            ->setOptimizeAutoloader($optimize)
            ->setClassMapAuthoritative($authoritative)
            ->setApcuAutoloader($apcu)
            ->setRunScripts(false);

        $ignorePlatformReqs = false;

        $reqsOption = $this->input->getOption('ignore-platform-reqs');

        if ($reqsOption !== null && $reqsOption !== false) {
            $ignorePlatformReqs = $reqsOption;
        } else {
            $reqOption = $this->input->getOption('ignore-platform-req');

            if ($reqOption !== null && $reqOption !== false) {
                $ignorePlatformReqs = $reqOption;
            }
        }

        $installer->setPlatformRequirementFilter(PlatformRequirementFilterFactory::fromBoolOrList($ignorePlatformReqs));

        $result = $installer->run();

        if (0 !== $result) {
            throw new RuntimeException(
                sprintf('Unable to restore Composer dependencies, installer exited with code %d.', $result),
            );
        }
    }
}
