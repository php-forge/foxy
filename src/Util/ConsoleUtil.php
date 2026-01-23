<?php

declare(strict_types=1);

namespace Foxy\Util;

use Composer\Config;
use Composer\IO\IOInterface;
use ReflectionClass;
use Symfony\Component\Console\Input\{ArgvInput, InputInterface};

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
        $ref = new ReflectionClass($io);

        if ($ref->hasProperty('input')) {
            $prop = $ref->getProperty('input');
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
