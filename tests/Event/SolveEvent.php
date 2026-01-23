<?php

declare(strict_types=1);

namespace Foxy\Tests\Event;

use Composer\Package\PackageInterface;
use Foxy\Event\AbstractSolveEvent;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use const DIRECTORY_SEPARATOR;

abstract class SolveEvent extends TestCase
{
    protected string $assetDir = '';
    protected PackageInterface|MockObject|array|null $packages = null;

    /**
     * Get the event instance.
     */
    abstract public function getEvent(): AbstractSolveEvent;

    public function testGetAssetDir(): void
    {
        $event = $this->getEvent();

        self::assertSame($this->assetDir, $event->getAssetDir());
    }

    public function testGetPackages(): void
    {
        $event = $this->getEvent();

        self::assertSame($this->packages, $event->getPackages());
    }

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->assetDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_event_test_', true);
        $this->packages = [$this->createMock(PackageInterface::class)];
    }

    protected function tearDown(): void
    {
        $this->assetDir = '';
        $this->packages = null;
    }
}
