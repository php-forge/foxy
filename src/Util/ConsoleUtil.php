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

namespace Foxy\Util;

use Composer\Config;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;

use const PHP_VERSION_ID;

/**
 * Helper for console.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class ConsoleUtil
{
    /**
     * Get the console input.
     *
     * @param IOInterface $io The IO
     */
    public static function getInput(IOInterface $io): InputInterface
    {
        $ref = new \ReflectionClass($io);

        if ($ref->hasProperty('input')) {
            $prop = $ref->getProperty('input');

            if (PHP_VERSION_ID < 80500) {
                $prop->setAccessible(true);
            }

            $input = $prop->getValue($io);

            if ($input instanceof InputInterface) {
                return $input;
            }
        }

        return new ArgvInput();
    }

    /**
     * Returns preferSource and preferDist values based on the configuration.
     *
     * @param Config $config The composer config.
     * @param InputInterface $input The console input
     *
     * @psalm-return list{bool, bool} An array composed of the preferSource and preferDist values
     */
    public static function getPreferredInstallOptions(Config $config, InputInterface $input): array
    {
        $preferSource = false;
        $preferDist = false;

        switch ($config->get('preferred-install')) {
            case 'source':
                $preferSource = true;

                break;

            case 'dist':
                $preferDist = true;

                break;

            case 'auto':
            default:
                break;
        }

        if ($input->getOption('prefer-source') || $input->getOption('prefer-dist')) {
            /** @var bool $preferSource */
            $preferSource = $input->getOption('prefer-source');
            /** @var bool $preferDist */
            $preferDist = $input->getOption('prefer-dist');
        }

        return [$preferSource, $preferDist];
    }
}
