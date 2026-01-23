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
use Foxy\Util\{ConsoleUtil, LockerUtil, PackageUtil};
use LogicException;
use Symfony\Component\Console\Input\InputInterface;

use function is_array;

final class ComposerFallback implements FallbackInterface
{
    private readonly Filesystem $fs;
    private array $lock = [];

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
        $this->fs = $fs ?: new Filesystem();
    }

    /**
     * @throws Exception
     */
    public function restore(): void
    {
        if (!$this->config->get('fallback-composer')) {
            return;
        }

        $this->io->write('<info>Fallback to previous state for Composer</info>');
        $hasLock = $this->restoreLockData();

        if ($hasLock) {
            $this->restorePreviousLockFile();
        } else {
            /** @var string $vendorDir */
            $vendorDir = $this->composer->getConfig()->get('vendor-dir');

            $this->fs->remove($vendorDir);
        }
    }

    public function save(): self
    {
        $rm = $this->composer->getRepositoryManager();
        $im = $this->composer->getInstallationManager();
        $composerFile = Factory::getComposerFile();
        $locker = LockerUtil::getLocker($this->io, $im, $composerFile);

        try {
            $lock = $locker->getLockData();
            $this->lock = PackageUtil::loadLockPackages($lock);
        } catch (LogicException) {
            $this->lock = [];
        }

        return $this;
    }

    /**
     * Get the installer.
     */
    private function getInstaller(): Installer
    {
        return $this->installer ?? Installer::create($this->io, $this->composer);
    }

    /**
     * Get the lock value.
     *
     * @param string $key The key.
     * @param mixed $default The default value.
     */
    private function getLockValue(string $key, mixed $default = null): mixed
    {
        return $this->lock[$key] ?? $default;
    }

    /**
     * Restore the data of lock file.
     */
    private function restoreLockData(): bool
    {
        /** @psalm-suppress MixedArgument */
        $this->composer->getLocker()->setLockData(
            $this->getLockValue('packages', []),
            $this->getLockValue('packages-dev'),
            $this->getLockValue('platform', []),
            $this->getLockValue('platform-dev', []),
            $this->getLockValue('aliases', []),
            $this->getLockValue('minimum-stability', ''),
            $this->getLockValue('stability-flags', []),
            $this->getLockValue('prefer-stable', false),
            $this->getLockValue('prefer-lowest', false),
            $this->getLockValue('platform-overrides', []),
        );

        $isLocked = $this->composer->getLocker()->isLocked();
        $lockData = $isLocked ? $this->composer->getLocker()->getLockData() : null;
        $hasPackage = is_array($lockData) && isset($lockData['packages']) && !empty($lockData['packages']);

        return $isLocked && $hasPackage;
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
        $optimize = $this->input->getOption('optimize-autoloader') || $config->get('optimize-autoloader');
        $authoritative = $this->input->getOption('classmap-authoritative') || $config->get('classmap-authoritative');
        $apcu = $this->input->getOption('apcu-autoloader') || $config->get('apcu-autoloader');
        $dispatcher = $this->composer->getEventDispatcher();
        /** @var bool $verbose */
        $verbose = $this->input->getOption('verbose');

        $installer = $this->getInstaller()
            ->setVerbose($verbose)
            ->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setDevMode(!$this->input->getOption('no-dev'))
            ->setDumpAutoloader(!$this->input->getOption('no-autoloader'))
            ->setOptimizeAutoloader($optimize)
            ->setClassMapAuthoritative($authoritative)
            ->setApcuAutoloader($apcu);

        $ignorePlatformReqs = $this->input->getOption('ignore-platform-reqs') ?: ($this->input->getOption('ignore-platform-req') ?: false);
        $installer->setPlatformRequirementFilter(PlatformRequirementFilterFactory::fromBoolOrList($ignorePlatformReqs));
        $dispatcher->setRunScripts(false);

        $installer->run();

        $dispatcher->setRunScripts(!$this->input->getOption('no-scripts'));
    }
}
