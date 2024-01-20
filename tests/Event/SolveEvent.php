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

namespace Foxy\Tests\Event;

use Composer\Package\PackageInterface;
use Foxy\Event\AbstractSolveEvent;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for solve events.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
abstract class SolveEvent extends \PHPUnit\Framework\TestCase
{
    protected string $assetDir = '';
    protected PackageInterface|MockObject|array|null $packages = null;

    protected function setUp(): void
    {
        $this->assetDir = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . uniqid('foxy_event_test_', true);
        $this->packages = [$this->createMock(PackageInterface::class)];
    }

    protected function tearDown(): void
    {
        $this->assetDir = '';
        $this->packages = null;
    }

    /**
     * Get the event instance.
     *
     * @return AbstractSolveEvent
     */
    abstract public function getEvent();

    public function testGetAssetDir(): void
    {
        $event = $this->getEvent();

        $this->assertSame($this->assetDir, $event->getAssetDir());
    }

    public function testGetPackages(): void
    {
        $event = $this->getEvent();

        $this->assertSame($this->packages, $event->getPackages());
    }
}
