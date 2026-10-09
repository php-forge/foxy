<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use Composer\Package\Link;
use Composer\Semver\Constraint\Constraint;

/**
 * Data provider for {@see \Foxy\Tests\Util\AssetUtilTest} test cases.
 */
final class AssetUtilProvider
{
    /**
     * @return iterable<int, array{bool, bool}>
     */
    public static function extraActivations(): iterable
    {
        yield [false, false];
        yield [true, false];
        yield [false, true];
        yield [true, true];
    }

    /**
     * @return iterable<int, array{list<Link>, list<Link>, bool}>
     */
    public static function foxyRequirements(): iterable
    {
        yield [
            [new Link('root/package', 'php-forge/foxy', new Constraint('=', '1.0.0'))],
            [],
            false,
        ];
        yield [
            [],
            [new Link('root/package', 'php-forge/foxy', new Constraint('=', '1.0.0'))],
            false,
        ];
        yield [
            [new Link('root/package', 'php-forge/foxy', new Constraint('=', '1.0.0'))],
            [],
            true,
        ];
        yield [
            [],
            [new Link('root/package', 'php-forge/foxy', new Constraint('=', '1.0.0'))],
            true,
        ];
    }

    /**
     * @return iterable<int, array{0: string, 1: string|null, 2: string, 3?: string}>
     */
    public static function packageVersions(): iterable
    {
        yield ['1.0.0', null, '1.0.0'];
        yield ['1.0.1', '1.0.0', '1.0.0'];
        yield ['1.0.0.x-dev', null, '1.0.0'];
        yield ['1.0.0.x', null, '1.0.0'];
        yield ['1.0.0.1', null, '1.0.0'];
        yield ['dev-master', null, '1.0.0', '1-dev'];
        yield ['dev-master', null, '1.0.0', '1.0-dev'];
        yield ['dev-master', null, '1.0.0', '1.0.0-dev'];
        yield ['dev-master', null, '1.0.0', '1.x-dev'];
        yield ['dev-master', null, '1.0.0', '1.0.x-dev'];
        yield ['dev-master', null, '1.0.0', '1.*-dev'];
        yield ['dev-master', null, '1.0.0', '1.0.*-dev'];
    }

    /**
     * @return iterable<int, array{string, bool}>
     */
    public static function projectActivations(): iterable
    {
        yield ['full/qualified', true];
        yield ['full-disable/qualified', false];
        yield ['foo/bar', true];
        yield ['baz/foo', false];
        yield ['baz/foo-test', false];
        yield ['bar/test', true];
        yield ['other/package', false];
        yield ['test-string/package', true];
    }

    /**
     * @return iterable<int, array{string, bool}>
     */
    public static function wildcardProjectActivations(): iterable
    {
        yield ['full/qualified', true];
        yield ['full-disable/qualified', false];
        yield ['foo/bar', true];
        yield ['baz/foo', false];
        yield ['baz/foo-test', false];
        yield ['bar/test', true];
        yield ['other/package', true];
        yield ['test-string/package', true];
    }
}
