<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Foxy\Asset\{AssetManagerFinder, AssetManagerInterface};
use Foxy\Exception\RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssetManagerFinderTest extends TestCase
{
    public static function availabilityChecks(): array
    {
        return [
            'enabled' => [true],
            'disabled' => [false],
        ];
    }

    #[DataProvider('availabilityChecks')]
    public function testFindManagerRejectsMultipleLockFiles(bool $checkAvailability): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Multiple asset manager lock files were found');

        $first = $this->createMock(AssetManagerInterface::class);
        $first->expects(self::once())->method('getName')->willReturn('first');
        $first->expects(self::once())->method('hasLockFile')->willReturn(true);
        $first->expects(self::never())->method('isAvailable');

        $second = $this->createMock(AssetManagerInterface::class);
        $second->expects(self::once())->method('getName')->willReturn('second');
        $second->expects(self::once())->method('hasLockFile')->willReturn(true);
        $second->expects(self::never())->method('isAvailable');

        (new AssetManagerFinder([$first, $second]))->findManager(checkAvailability: $checkAvailability);
    }

    public function testFindManagerRejectsUnavailableManagerSelectedByLockFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The asset manager "foo" selected by its lock file is not available');

        $am = $this->createMock(AssetManagerInterface::class);
        $am->expects(self::exactly(2))->method('getName')->willReturn('foo');
        $am->expects(self::once())->method('hasLockFile')->willReturn(true);
        $am->expects(self::once())->method('isAvailable')->willReturn(false);

        (new AssetManagerFinder([$am]))->findManager();
    }

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
        $am->expects(self::once())->method('isAvailable')->willReturn(true);

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

    public function testFindManagerWithDisabledAvailabilityCheckUsesFirstManagerWithoutProbing(): void
    {
        $first = $this->createMock(AssetManagerInterface::class);
        $first->expects(self::once())->method('getName')->willReturn('first');
        $first->expects(self::once())->method('hasLockFile')->willReturn(false);
        $first->expects(self::never())->method('isAvailable');

        $second = $this->createMock(AssetManagerInterface::class);
        $second->expects(self::once())->method('getName')->willReturn('second');
        $second->expects(self::once())->method('hasLockFile')->willReturn(false);
        $second->expects(self::never())->method('isAvailable');

        $res = (new AssetManagerFinder([$first, $second]))->findManager(checkAvailability: false);

        self::assertSame($first, $res);
    }

    public function testFindManagerWithDisabledAvailabilityCheckUsesLockFileWithoutProbing(): void
    {
        $am = $this->createMock(AssetManagerInterface::class);

        $am->expects(self::once())->method('getName')->willReturn('foo');
        $am->expects(self::once())->method('hasLockFile')->willReturn(true);
        $am->expects(self::never())->method('isAvailable');

        $res = (new AssetManagerFinder([$am]))->findManager(checkAvailability: false);

        self::assertSame($am, $res);
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
