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

use Foxy\Event\GetAssetsEvent;

/**
 * Tests for get assets event.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class GetAssetsEventTest extends SolveEvent
{
    private array $assets = ['@composer-asset/foo--bar' => 'file:./vendor/foxy/composer-asset/foo/bar'];

    public function getEvent(): GetAssetsEvent
    {
        return new GetAssetsEvent($this->assetDir, $this->packages, $this->assets);
    }

    public function testHasAsset(): void
    {
        $event = $this->getEvent();

        $this->assertTrue($event->hasAsset('@composer-asset/foo--bar'));
    }

    public function testAddAsset(): void
    {
        $assetPackageName = '@composer-asset/bar--foo';
        $assetPackagePath = 'file:./vendor/foxy/composer-asset/bar/foo';
        $event = $this->getEvent();

        $this->assertFalse($event->hasAsset($assetPackageName));

        $event->addAsset($assetPackageName, $assetPackagePath);

        $this->assertTrue($event->hasAsset($assetPackageName));
    }

    public function testGetAssets(): void
    {
        $event = $this->getEvent();
        $this->assertSame($this->assets, $event->getAssets());

        $expectedAssets = [
            '@composer-asset/foo--bar' => 'file:./vendor/foxy/composer-asset/foo/bar',
            '@composer-asset/bar--foo' => 'file:./vendor/foxy/composer-asset/bar/foo',
        ];

        $event->addAsset('@composer-asset/bar--foo', 'file:./vendor/foxy/composer-asset/bar/foo');

        $this->assertSame($expectedAssets, $event->getAssets());
    }
}
