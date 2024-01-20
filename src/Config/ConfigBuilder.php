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

namespace Foxy\Config;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;

/**
 * Plugin Config builder.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
abstract class ConfigBuilder
{
    /**
     * Build the config of plugin.
     *
     * @param Composer $composer The composer.
     * @param array $defaults The default values.
     * @param IOInterface|null $io The composer input/output.
     */
    public static function build(Composer $composer, array $defaults = [], IOInterface $io = null): Config
    {
        $config = self::getConfigBase($composer, $io);

        return new Config($config, $defaults);
    }

    /**
     * Get the base of data.
     *
     * @param Composer $composer The composer.
     * @param IOInterface|null $io The composer input/output.
     */
    private static function getConfigBase(Composer $composer, IOInterface $io = null): array
    {
        $globalPackageConfig = self::getGlobalConfig($composer, 'composer', $io);
        $globalConfig = self::getGlobalConfig($composer, 'config', $io);
        $packageConfig = $composer->getPackage()->getConfig();
        $packageConfig = isset($packageConfig['foxy']) && \is_array($packageConfig['foxy'])
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
     */
    private static function getGlobalConfig(Composer $composer, string $filename, IOInterface $io = null): array
    {
        $home = self::getComposerHome($composer);
        $file = new JsonFile($home . '/' . $filename . '.json');
        $config = [];

        if ($file->exists()) {
            $data = $file->read();

            if (isset($data['config']['foxy']) && \is_array($data['config']['foxy'])) {
                $config = $data['config']['foxy'];

                if ($io instanceof IOInterface && $io->isDebug()) {
                    $io->writeError('Loading Foxy config in file ' . $file->getPath());
                }
            }
        }

        return $config;
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
}
