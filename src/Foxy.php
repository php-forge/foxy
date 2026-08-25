<?php

declare(strict_types=1);

namespace Foxy;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\{PackageEvent, PackageEvents};
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\{Event, ScriptEvents};
use Composer\Util\{Filesystem, ProcessExecutor};
use Foxy\Asset\{AbstractAssetManager, AssetManagerFinder, AssetManagerInterface};
use Foxy\Asset\{BunManager, NpmManager, PnpmManager, YarnManager};
use Foxy\Config\{Config, ConfigBuilder};
use Foxy\Exception\RuntimeException;
use Foxy\Fallback\{AssetFallback, ComposerFallback};
use Foxy\Solver\{Solver, SolverInterface};
use Foxy\Util\{ComposerUtil, ConsoleUtil};
use Seld\JsonLint\ParsingException;

use function str_contains;

final class Foxy implements PluginInterface, EventSubscriberInterface
{
    final public const string REQUIRED_COMPOSER_VERSION = '^2.10.2';

    private AssetFallback $assetFallback;

    private AssetManagerInterface $assetManager;

    /**
     * @var list<class-string<AssetManagerInterface>> The list of the classes of asset managers.
     */
    private static array $assetManagers = [
        NpmManager::class,
        PnpmManager::class,
        YarnManager::class,
        BunManager::class,
    ];

    private ComposerFallback $composerFallback;
    private string $composerVersion = Composer::VERSION;
    private Config $config;

    /**
     * The default values of config.
     */
    private static array $defaultConfig = [
        'enabled' => true,
        'manager' => null,
        'manager-version' => [
            'bun' => '>=1.1.0',
            'npm' => '>=5.0.0',
            'pnpm' => '>=7.0.0',
            'yarn' => '>=1.0.0',
        ],
        'manager-bin' => null,
        'manager-options' => null,
        'manager-install-options' => null,
        'manager-update-options' => null,
        'manager-timeout' => null,
        'composer-asset-dir' => null,
        'run-asset-manager' => true,
        'fallback-asset' => true,
        'fallback-composer' => true,
        'enable-packages' => [],
    ];

    private bool $initialized = false;

    private SolverInterface $solver;

    /**
     * @throws ParsingException
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        ComposerUtil::validateVersion(self::REQUIRED_COMPOSER_VERSION, $this->composerVersion);

        $input = ConsoleUtil::getInput($io);

        $executor = new ProcessExecutor($io);
        $fs = new Filesystem($executor);

        $this->config = ConfigBuilder::build($composer, self::$defaultConfig, $io);

        if (!$this->isEnabled()) {
            return;
        }

        $this->assetManager = $this->getAssetManager($io, $this->config, $executor, $fs);
        $packageJsonPath = $this->assetManager instanceof AbstractAssetManager
            ? $this->assetManager->getPackageJsonPath()
            : $this->assetManager->getPackageName();

        $this->assetFallback = new AssetFallback($io, $this->config, $packageJsonPath, $fs);
        $this->composerFallback = new ComposerFallback($composer, $io, $this->config, $input, $fs);
        $this->solver = new Solver($this->assetManager, $this->config, $fs, $this->composerFallback);

        $this->assetManager->setFallback($this->assetFallback);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Do nothing
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ComposerUtil::getInitEventName() => [['init', 100]],
            PackageEvents::POST_PACKAGE_INSTALL => [['initOnInstall', 100]],
            ScriptEvents::POST_INSTALL_CMD => [['solveAssets', 100]],
            ScriptEvents::POST_UPDATE_CMD => [['solveAssets', 100]],
        ];
    }

    /**
     * Init the plugin.
     */
    public function init(): void
    {
        if (!$this->initialized) {
            $this->initialized = true;

            if (!$this->isEnabled()) {
                return;
            }

            $this->assetFallback->save();
            $this->composerFallback->save();

            if ($this->isEnabled('run-asset-manager')) {
                $this->assetManager->validate();
            }
        }
    }

    /**
     * Init the plugin just after the first installation.
     *
     * @param PackageEvent $event The package event
     */
    public function initOnInstall(PackageEvent $event): void
    {
        $operation = $event->getOperation();

        if ($operation instanceof InstallOperation && 'php-forge/foxy' === $operation->getPackage()->getName()) {
            $this->init();
        }
    }

    /**
     * @param SolverInterface $solver The solver instance.
     */
    public function setSolver(SolverInterface $solver): void
    {
        $this->solver = $solver;
    }

    /**
     * @param Event $event The composer script event.
     */
    public function solveAssets(Event $event): void
    {
        if (isset($this->config) && !$this->isEnabled()) {
            return;
        }

        $this->solver->setUpdatable(str_contains($event->getName(), 'update'));
        $this->solver->solve($event->getComposer(), $event->getIO());
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Do nothing
    }

    /**
     * @param IOInterface $io The IO interface.
     * @param Config $config The config of plugin.
     * @param ProcessExecutor $executor The process executor.
     * @param Filesystem $fs The composer filesystem.
     *
     * @throws RuntimeException When the asset manager is not found.
     */
    private function getAssetManager(
        IOInterface $io,
        Config $config,
        ProcessExecutor $executor,
        Filesystem $fs,
    ): AssetManagerInterface {
        $amf = new AssetManagerFinder();

        foreach (self::$assetManagers as $class) {
            $amf->addManager(new $class($io, $config, $executor, $fs));
        }

        /** @var string|null $manager */
        $manager = $config->get('manager');

        return $amf->findManager($manager);
    }

    /**
     * Check whether plugin execution is enabled.
     */
    private function isEnabled(string $key = 'enabled'): bool
    {
        // Composer can load this entry point with the previous Config class during a plugin self-update.
        $value = $this->config->get($key);

        return true === $value || 1 === $value || '1' === $value;
    }
}
