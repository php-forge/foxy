<?php

declare(strict_types=1);

namespace Foxy\Fallback;

use Composer\Autoload\AutoloadGenerator;
use Composer\{Composer, Factory};
use Composer\EventDispatcher\EventDispatcher;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Installer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Util\Filesystem;
use Exception;
use Foxy\Config\Config;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Util\{ConsoleUtil, PackageUtil};
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

use function is_link;

/**
 * Restores Composer's lock file and installed dependencies after an asset failure.
 */
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
     * Creates a Composer state fallback.
     *
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
     * Restores the captured Composer state without running project scripts.
     *
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

    /**
     * Captures the lock file and vendor-directory state when Composer fallback is enabled.
     */
    public function save(): self
    {
        $this->resetSnapshot();

        if (!$this->isEnabled()) {
            return $this;
        }

        $vendorDir = $this->composer->getConfig()->get('vendor-dir');

        $this->vendorDir = '' !== $vendorDir ? $vendorDir : null;

        $this->vendorDirExisted = null !== $this->vendorDir && file_exists($this->vendorDir);

        $lockFile = new JsonFile(Factory::getLockFile(Factory::getComposerFile()), null, $this->io);

        $this->lockFile = $lockFile->getPath();
        $this->lockFileExisted = $lockFile->exists();

        if ($this->lockFileExisted) {
            $this->lock = $lockFile->read();
        }

        $this->snapshotSaved = true;

        return $this;
    }

    /**
     * Creates the rollback installer over a private, subscriber-free event dispatcher with scripts disabled.
     *
     * Plugin listeners, Foxy's own included, and project scripts registered on the shared dispatcher never see the
     * rollback; the private autoload generator keeps `pre-autoload-dump` and `post-autoload-dump` off it as well.
     */
    private function createInstaller(): Installer
    {
        $dispatcher = (new EventDispatcher($this->composer, $this->io))->setRunScripts(false);

        return new Installer(
            $this->io,
            $this->composer->getConfig(),
            // @phpstan-ignore argument.type (root packages all extend BasePackage, as Installer::create() assumes)
            $this->composer->getPackage(),
            $this->composer->getDownloadManager(),
            $this->composer->getRepositoryManager(),
            $this->composer->getLocker(),
            $this->composer->getInstallationManager(),
            $dispatcher,
            new AutoloadGenerator($dispatcher, $this->io),
        );
    }

    private function getHydratedLock(): array
    {
        return $this->hydratedLock ??= PackageUtil::loadLockPackages($this->lock);
    }

    private function getInstaller(): Installer
    {
        return $this->installer ?? $this->createInstaller();
    }

    private function isEnabled(): bool
    {
        return $this->config->isEnabled('fallback-composer');
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
                Message::FALLBACK_COMPOSER_REMOVE_FAILED->getMessage($path),
                0,
                $exception,
            );
        }

        if (!$removed && $this->pathExists($path)) {
            throw new RuntimeException(
                Message::FALLBACK_COMPOSER_REMOVE_FAILED->getMessage($path),
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
            ->setApcuAutoloader($apcu);

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
                Message::FALLBACK_COMPOSER_RESTORE_FAILED->getMessage($result),
            );
        }
    }
}
