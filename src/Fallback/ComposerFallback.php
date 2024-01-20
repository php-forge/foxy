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

namespace Foxy\Fallback;

use Composer\Composer;
use Composer\Factory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Installer;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Foxy\Config\Config;
use Foxy\Util\ConsoleUtil;
use Foxy\Util\LockerUtil;
use Foxy\Util\PackageUtil;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Composer fallback.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class ComposerFallback implements FallbackInterface
{
    protected Filesystem $fs;
    protected array $lock = [];

    /**
     * Constructor.
     *
     * @param Composer $composer The composer.
     * @param IOInterface $io The IO.
     * @param Config $config The config.
     * @param InputInterface $input The input.
     * @param Filesystem|null $fs The composer filesystem.
     * @param Installer|null $installer The installer.
     */
    public function __construct(
        protected Composer $composer,
        protected IOInterface $io,
        protected Config $config,
        protected InputInterface $input,
        Filesystem $fs = null,
        protected Installer|null $installer = null
    ) {
        $this->fs = $fs ?: new Filesystem();
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
        } catch (\LogicException) {
            $this->lock = [];
        }

        return $this;
    }

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
            $this->fs->remove($this->composer->getConfig()->get('vendor-dir'));
        }
    }

    /**
     * Restore the data of lock file.
     */
    protected function restoreLockData(): bool
    {
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
            $this->getLockValue('platform-overrides', [])
        );

        $isLocked = $this->composer->getLocker()->isLocked();
        $lockData = $isLocked ? $this->composer->getLocker()->getLockData() : null;
        $hasPackage = \is_array($lockData) && isset($lockData['packages']) && !empty($lockData['packages']);

        return $isLocked && $hasPackage;
    }

    /**
     * Restore the PHP dependencies with the previous lock file.
     */
    protected function restorePreviousLockFile(): void
    {
        $config = $this->composer->getConfig();
        [$preferSource, $preferDist] = ConsoleUtil::getPreferredInstallOptions($config, $this->input);
        $optimize = $this->input->getOption('optimize-autoloader') || $config->get('optimize-autoloader');
        $authoritative = $this->input->getOption('classmap-authoritative') || $config->get('classmap-authoritative');
        $apcu = $this->input->getOption('apcu-autoloader') || $config->get('apcu-autoloader');
        $dispatcher = $this->composer->getEventDispatcher();

        $installer = $this->getInstaller()
            ->setVerbose($this->input->getOption('verbose'))
            ->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setDevMode(!$this->input->getOption('no-dev'))
            ->setDumpAutoloader(!$this->input->getOption('no-autoloader'))
            ->setOptimizeAutoloader($optimize)
            ->setClassMapAuthoritative($authoritative)
            ->setApcuAutoloader($apcu)
        ;

        // @codeCoverageIgnoreStart
        if (\defined('Composer\Composer::RUNTIME_API_VERSION') && version_compare(Composer::RUNTIME_API_VERSION, '2.2.0', '>=')) {
            $ignorePlatformReqs = $this->input->getOption('ignore-platform-reqs') ?: ($this->input->getOption('ignore-platform-req') ?: false);
            $installer->setPlatformRequirementFilter(PlatformRequirementFilterFactory::fromBoolOrList($ignorePlatformReqs));
            $dispatcher->setRunScripts(false);
        } else {
            $installer->setPlatformRequirementFilter($this->input->getOption('ignore-platform-reqs'));
        }

        if (method_exists($installer, 'setSkipSuggest')) {
            $installer->setSkipSuggest(true);
        }
        // @codeCoverageIgnoreEnd

        $installer->run();

        // @codeCoverageIgnoreStart
        if (\defined('Composer\Composer::RUNTIME_API_VERSION') && version_compare(Composer::RUNTIME_API_VERSION, '2.2.0', '>=')) {
            $dispatcher->setRunScripts(!$this->input->getOption('no-scripts'));
        }
        // @codeCoverageIgnoreEnd
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
     * Get the installer.
     */
    private function getInstaller(): Installer
    {
        return $this->installer ?? Installer::create($this->io, $this->composer);
    }
}
