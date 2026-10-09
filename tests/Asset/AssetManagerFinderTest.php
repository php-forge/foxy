<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Foxy\Asset\{AssetManagerFinder, AssetManagerInterface};
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Tests\Provider\AssetManagerFinderProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AssetManagerFinder} asset manager resolution by configuration, lock file, and availability.
 *
 * {@see AssetManagerFinderProvider} for test case data providers.
 */
final class AssetManagerFinderTest extends TestCase
{
    #[DataProviderExternal(AssetManagerFinderProvider::class, 'availabilityChecks')]
    public function testFindManagerRejectsMultipleLockFiles(bool $checkAvailability): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_MANAGER_LOCK_FILES_AMBIGUOUS->getMessage(),
        );

        $first = $this->createMock(AssetManagerInterface::class);

        $first
            ->expects(self::once())
            ->method('getName')
            ->willReturn('first');
        $first
            ->expects(self::once())
            ->method('hasLockFile')
            ->willReturn(true);
        $first
            ->expects(self::never())
            ->method('isAvailable');

        $second = $this->createMock(AssetManagerInterface::class);

        $second
            ->expects(self::once())
            ->method('getName')
            ->willReturn('second');
        $second
            ->expects(self::once())
            ->method('hasLockFile')
            ->willReturn(true);
        $second
            ->expects(self::never())
            ->method('isAvailable');

        (new AssetManagerFinder([$first, $second]))->findManager(checkAvailability: $checkAvailability);
    }

    public function testFindManagerRejectsUnavailableManagerSelectedByLockFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_MANAGER_LOCKED_UNAVAILABLE->getMessage('foo'),
        );

        $am = $this->createMock(AssetManagerInterface::class);

        $am
            ->expects(self::exactly(2))
            ->method('getName')
            ->willReturn('foo');
        $am
            ->expects(self::once())
            ->method('hasLockFile')
            ->willReturn(true);
        $am
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(false);

        (new AssetManagerFinder([$am]))->findManager();
    }

    public function testFindManagerWithAutoManagerAndAvailableManagerByAvailability(): void
    {
        $am = $this->createMock(AssetManagerInterface::class);

        $am
            ->expects(self::once())
            ->method('getName')
            ->willReturn('foo');
        $am
            ->expects(self::once())
            ->method('hasLockFile')
            ->willReturn(false);
        $am
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(true);

        $amf = new AssetManagerFinder([$am]);

        $res = $amf->findManager();

        self::assertSame(
            $am,
            $res,
            'The available manager must be selected.',
        );
    }

    public function testFindManagerWithAutoManagerAndAvailableManagerByLockFile(): void
    {
        $am = $this->createMock(AssetManagerInterface::class);

        $am
            ->expects(self::once())
            ->method('getName')
            ->willReturn('foo');
        $am
            ->expects(self::once())
            ->method('hasLockFile')
            ->willReturn(true);
        $am
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(true);

        $amf = new AssetManagerFinder([$am]);

        $res = $amf->findManager();

        self::assertSame(
            $am,
            $res,
            'The manager with the lock file must be selected.',
        );
    }

    public function testFindManagerWithAutoManagerAndNoAvailableManager(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_MANAGER_NONE_FOUND->getMessage(),
        );

        $am = $this->getMockBuilder(AssetManagerInterface::class)->getMock();

        $am
            ->expects(self::atLeastOnce())
            ->method('getName')
            ->willReturn('foo');
        $am
            ->expects(self::once())
            ->method('hasLockFile')
            ->willReturn(false);
        $am
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(false);

        $amf = new AssetManagerFinder([$am]);

        $amf->findManager();
    }

    public function testFindManagerWithDisabledAvailabilityCheckUsesFirstManagerWithoutProbing(): void
    {
        $first = $this->createMock(AssetManagerInterface::class);

        $first
            ->expects(self::once())
            ->method('getName')
            ->willReturn('first');
        $first
            ->expects(self::once())
            ->method('hasLockFile')
            ->willReturn(false);
        $first
            ->expects(self::never())
            ->method('isAvailable');

        $second = $this->createMock(AssetManagerInterface::class);

        $second
            ->expects(self::once())
            ->method('getName')
            ->willReturn('second');
        $second
            ->expects(self::once())
            ->method('hasLockFile')
            ->willReturn(false);
        $second
            ->expects(self::never())
            ->method('isAvailable');

        $res = (new AssetManagerFinder([$first, $second]))->findManager(checkAvailability: false);

        self::assertSame(
            $first,
            $res,
            'The first manager must be selected without probing availability.',
        );
    }

    public function testFindManagerWithDisabledAvailabilityCheckUsesLockFileWithoutProbing(): void
    {
        $am = $this->createMock(AssetManagerInterface::class);

        $am
            ->expects(self::once())
            ->method('getName')
            ->willReturn('foo');
        $am
            ->expects(self::once())
            ->method('hasLockFile')
            ->willReturn(true);
        $am
            ->expects(self::never())
            ->method('isAvailable');

        $res = (new AssetManagerFinder([$am]))->findManager(checkAvailability: false);

        self::assertSame(
            $am,
            $res,
            'The manager with the lock file must be selected without probing.',
        );
    }

    public function testFindManagerWithInvalidManager(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_MANAGER_UNKNOWN->getMessage('bar'),
        );

        $am = $this->createMock(AssetManagerInterface::class);

        $am
            ->expects(self::once())
            ->method('getName')
            ->willReturn('foo');

        $amf = new AssetManagerFinder([$am]);

        $amf->findManager('bar');
    }

    public function testFindManagerWithValidManager(): void
    {
        $am = $this->createMock(AssetManagerInterface::class);

        $am
            ->expects(self::once())
            ->method('getName')
            ->willReturn('foo');

        $amf = new AssetManagerFinder([$am]);

        $res = $amf->findManager('foo');

        self::assertSame(
            $am,
            $res,
            'The explicitly requested manager must be selected.',
        );
    }
}
