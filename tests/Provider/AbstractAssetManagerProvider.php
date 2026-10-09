<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Asset\AbstractAssetManagerTest} test cases.
 */
final class AbstractAssetManagerProvider
{
    /**
     * @return iterable<int, array{string, string}>
     */
    public static function relativeRootPackageDirectories(): iterable
    {
        yield ['prefixC:', 'prefixC:'];
        yield ['C:directory', 'C:directory'];
        yield ['relative/C:/directory', 'relative/C:/directory'];
    }
}
