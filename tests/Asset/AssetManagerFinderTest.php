<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Foxy\Asset\{AssetManagerFinder, AssetManagerInterface};
use Foxy\Exception\RuntimeException;
use PHPUnit\Framework\TestCase;

final class AssetManagerFinderTest extends TestCase
{
    public function testFindManagerWithAutoManagerAndAvailableManagerByAvailability(): void
    {
        $am = $this->createMock(AssetManagerInterface::class);

        $am->expects(self::once())->method('getName')->willReturn('foo');
        $am->expects(self::once())->method('hasLockFile')->willReturn(false);
        $am->expects(self::once())->method('isAvailable')->willReturn(true);

        $amf = new AssetManagerFinder([$am]);

        $res = $amf->findManager();

        self::assertSame(
            $am,
            $res,
        );
    }

    public function testFindManagerWithAutoManagerAndAvailableManagerByLockFile(): void
    {
        $am = $this->createMock(AssetManagerInterface::class);

        $am->expects(self::once())->method('getName')->willReturn('foo');
        $am->expects(self::once())->method('hasLockFile')->willReturn(true);
        $am->expects(self::never())->method('isAvailable');

        $amf = new AssetManagerFinder([$am]);

        $res = $amf->findManager();

        self::assertSame(
            $am,
            $res,
        );
    }

    public function testFindManagerWithAutoManagerAndNoAvailableManager(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No asset manager is found');

        $am = $this->getMockBuilder(AssetManagerInterface::class)->getMock();

        $am->expects(self::atLeastOnce())->method('getName')->willReturn('foo');
        $am->expects(self::once())->method('hasLockFile')->willReturn(false);
        $am->expects(self::once())->method('isAvailable')->willReturn(false);

        $amf = new AssetManagerFinder([$am]);

        $amf->findManager();
    }

    public function testFindManagerWithInvalidManager(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The asset manager "bar" doesn\'t exist');

        $am = $this->createMock(AssetManagerInterface::class);

        $am->expects(self::once())->method('getName')->willReturn('foo');

        $amf = new AssetManagerFinder([$am]);

        $amf->findManager('bar');
    }

    public function testFindManagerWithValidManager(): void
    {
        $am = $this->createMock(AssetManagerInterface::class);

        $am->expects(self::once())->method('getName')->willReturn('foo');

        $amf = new AssetManagerFinder([$am]);
        $res = $amf->findManager('foo');

        self::assertSame(
            $am,
            $res,
        );
    }
}
