<?php

declare(strict_types=1);

namespace Foxy\Tests\Event;

use Foxy\Event\GetAssetsEvent;

final class GetAssetsEventTest extends SolveEvent
{
    private array $assets = ['@composer-asset/foo--bar' => 'file:./vendor/foxy/composer-asset/foo/bar'];

    public function getEvent(): GetAssetsEvent
    {
        return new GetAssetsEvent($this->assetDir, $this->packages, $this->assets);
    }

    public function testAddAsset(): void
    {
        $assetPackageName = '@composer-asset/bar--foo';
        $assetPackagePath = 'file:./vendor/foxy/composer-asset/bar/foo';
        $event = $this->getEvent();

        self::assertFalse(
            $event->hasAsset($assetPackageName),
        );

        $event->addAsset($assetPackageName, $assetPackagePath);

        self::assertTrue(
            $event->hasAsset($assetPackageName),
        );
    }

    public function testGetAssets(): void
    {
        $event = $this->getEvent();

        self::assertSame(
            $this->assets,
            $event->getAssets(),
        );

        $expectedAssets = [
            '@composer-asset/foo--bar' => 'file:./vendor/foxy/composer-asset/foo/bar',
            '@composer-asset/bar--foo' => 'file:./vendor/foxy/composer-asset/bar/foo',
        ];

        $event->addAsset('@composer-asset/bar--foo', 'file:./vendor/foxy/composer-asset/bar/foo');

        self::assertSame(
            $expectedAssets,
            $event->getAssets(),
        );
    }

    public function testHasAsset(): void
    {
        $event = $this->getEvent();

        self::assertTrue(
            $event->hasAsset('@composer-asset/foo--bar'),
        );
    }
}
