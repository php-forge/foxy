<?php

declare(strict_types=1);

namespace Foxy\Config;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Seld\JsonLint\ParsingException;

use function array_merge;
use function is_array;
use function is_string;

abstract class ConfigBuilder
{
    /**
     * Build the config of plugin.
     *
     * @param Composer $composer The composer.
     * @param array $defaults The default values.
     * @param IOInterface|null $io The composer input/output.
     *
     * @throws ParsingException
     */
    public static function build(Composer $composer, array $defaults = [], IOInterface|null $io = null): Config
    {
        $config = self::getConfigBase($composer, $io);

        return new Config($config, $defaults);
    }

    /**
     * Get the home directory of composer.
     *
     * @param Composer $composer The composer
     */
    private static function getComposerHome(Composer $composer): string
    {
        $composerHome = $composer->getConfig()->get('home');

        return is_string($composerHome) ? $composerHome : '';
    }

    /**
     * Get the base of data.
     *
     * @param Composer $composer The composer.
     * @param IOInterface|null $io The composer input/output.
     *
     * @throws ParsingException
     */
    private static function getConfigBase(Composer $composer, IOInterface|null $io = null): array
    {
        $globalPackageConfig = self::getGlobalConfig($composer, 'composer', $io);
        $globalConfig = self::getGlobalConfig($composer, 'config', $io);
        $packageConfig = $composer->getPackage()->getConfig();
        $packageConfig = isset($packageConfig['foxy']) && is_array($packageConfig['foxy'])
            ? $packageConfig['foxy']
            : [];

        return array_merge($globalPackageConfig, $globalConfig, $packageConfig);
    }

    /**
     * Get the data of the global config.
     *
     * @param Composer $composer The composer.
     * @param string $filename The filename.
     * @param IOInterface|null $io The composer input/output.
     *
     * @throws ParsingException
     */
    private static function getGlobalConfig(Composer $composer, string $filename, IOInterface|null $io = null): array
    {
        $home = self::getComposerHome($composer);
        $file = new JsonFile($home . '/' . $filename . '.json');
        $config = [];

        if ($file->exists()) {
            /** @var array $data */
            $data = $file->read();

            if (isset($data['config']['foxy']) && is_array($data['config']['foxy'])) {
                $config = $data['config']['foxy'];

                if ($io instanceof IOInterface && $io->isDebug()) {
                    $io->writeError('Loading Foxy config in file ' . $file->getPath());
                }
            }
        }

        return $config;
    }
}
