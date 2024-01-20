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

namespace Foxy\Tests\Asset;

use Foxy\Asset\AssetManagerFinder;
use PHPUnit\Framework\TestCase;

/**
 * Asset manager finder tests.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class AssetManagerFinderTest extends TestCase
{
    public function testFindManagerWithValidManager(): void
    {
        $am = $this->createMock(\Foxy\Asset\AssetManagerInterface::class);

        $am->expects($this->once())->method('getName')->willReturn('foo');

        $amf = new AssetManagerFinder([$am]);
        $res = $amf->findManager('foo');

        $this->assertSame($am, $res);
    }

    public function testFindManagerWithInvalidManager(): void
    {
        $this->expectException(\Foxy\Exception\RuntimeException::class);
        $this->expectExceptionMessage('The asset manager "bar" doesn\'t exist');

        $am = $this->createMock(\Foxy\Asset\AssetManagerInterface::class);

        $am->expects($this->once())->method('getName')->willReturn('foo');

        $amf = new AssetManagerFinder([$am]);

        $amf->findManager('bar');
    }

    public function testFindManagerWithAutoManagerAndAvailableManagerByLockFile(): void
    {
        $am = $this->createMock(\Foxy\Asset\AssetManagerInterface::class);

        $am->expects($this->once())->method('getName')->willReturn('foo');
        $am->expects($this->once())->method('hasLockFile')->willReturn(true);
        $am->expects($this->never())->method('isAvailable');

        $amf = new AssetManagerFinder([$am]);

        $res = $amf->findManager(null);

        $this->assertSame($am, $res);
    }

    public function testFindManagerWithAutoManagerAndAvailableManagerByAvailability(): void
    {
        $am = $this->createMock(\Foxy\Asset\AssetManagerInterface::class);

        $am->expects($this->once())->method('getName')->willReturn('foo');
        $am->expects($this->once())->method('hasLockFile')->willReturn(false);
        $am->expects($this->once())->method('isAvailable')->willReturn(true);

        $amf = new AssetManagerFinder([$am]);

        $res = $amf->findManager(null);

        $this->assertSame($am, $res);
    }

    public function testFindManagerWithAutoManagerAndNoAvailableManager(): void
    {
        $this->expectException(\Foxy\Exception\RuntimeException::class);
        $this->expectExceptionMessage('No asset manager is found');

        $am = $this->getMockBuilder(\Foxy\Asset\AssetManagerInterface::class)->getMock();

        $am->expects($this->atLeastOnce())->method('getName')->willReturn('foo');
        $am->expects($this->once())->method('hasLockFile')->willReturn(false);
        $am->expects($this->once())->method('isAvailable')->willReturn(false);

        $amf = new AssetManagerFinder([$am]);

        $amf->findManager(null);
    }
}
