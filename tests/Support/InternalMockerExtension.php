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

namespace Foxy\Tests\Support;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Xepozz\InternalMocker\Mocker;
use Xepozz\InternalMocker\MockerState;

final class InternalMockerExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class () implements StartedSubscriber {
                public function notify(Started $event): void
                {
                    InternalMockerExtension::load();
                }
            },
            new class () implements PreparationStartedSubscriber {
                public function notify(PreparationStarted $event): void
                {
                    MockerState::resetState();
                }
            },
        );
    }

    public static function load(): void
    {
        $mocks = [
            [
                'namespace' => 'Foxy\\Asset',
                'name' => 'getcwd',
            ],
            [
                'namespace' => 'Foxy\\Asset',
                'name' => 'chdir',
            ],
            [
                'namespace' => 'Foxy\\Json',
                'name' => 'file_get_contents',
            ],
            [
                'namespace' => 'Foxy\\Fallback',
                'name' => 'file_get_contents',
            ],
            [
                'namespace' => 'Foxy\\Fallback',
                'name' => 'file_put_contents',
            ],
            [
                'namespace' => 'Foxy\\Fallback',
                'name' => 'file_exists',
            ],
            [
                'namespace' => 'Foxy\\Fallback',
                'name' => 'is_file',
            ],
        ];

        $mocksPath = __DIR__ . '/../../.phpunit.cache/internal-mocker/mocks.php';
        $stubPath = __DIR__ . '/internal-mocker-stubs.php';

        $mocker = new Mocker($mocksPath, $stubPath);
        $mocker->load($mocks);

        MockerState::saveState();
    }
}
