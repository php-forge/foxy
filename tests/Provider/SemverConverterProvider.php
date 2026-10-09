<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Converter\SemverConverterTest} test cases.
 */
final class SemverConverterProvider
{
    /**
     * @return iterable<int, array{string|null, string}>
     */
    public static function versions(): iterable
    {
        yield ['1.2.3', '1.2.3'];
        yield ['1.2.3alpha', '1.2.3-alpha1'];
        yield ['1.2.3-alpha', '1.2.3-alpha1'];
        yield ['1.2.3a', '1.2.3-alpha1'];
        yield ['1.2.3a1', '1.2.3-alpha1'];
        yield ['1.2.3-a', '1.2.3-alpha1'];
        yield ['1.2.3-a1', '1.2.3-alpha1'];
        yield ['1.2.3a2', '1.2.3-alpha2'];
        yield ['1.2.3b', '1.2.3-beta1'];
        yield ['1.2.3b1', '1.2.3-beta1'];
        yield ['1.2.3-b', '1.2.3-beta1'];
        yield ['1.2.3-b1', '1.2.3-beta1'];
        yield ['1.2.3b12', '1.2.3-beta12'];
        yield ['1.2.3beta', '1.2.3-beta1'];
        yield ['1.2.3-beta', '1.2.3-beta1'];
        yield ['1.2.3beta1', '1.2.3-beta1'];
        yield ['1.2.3-beta1', '1.2.3-beta1'];
        yield ['1.2.3rc1', '1.2.3-RC1'];
        yield ['1.2.3-rc1', '1.2.3-RC1'];
        yield ['1.2.3rc2', '1.2.3-RC2'];
        yield ['1.2.3-rc2', '1.2.3-RC2'];
        yield ['1.2.3rc.2', '1.2.3-RC.2'];
        yield ['1.2.3-rc.2', '1.2.3-RC.2'];
        yield ['1.2.3+0', '1.2.3-patch0'];
        yield ['1.2.3-0', '1.2.3-patch0'];
        yield ['1.2.3pre', '1.2.3-beta1'];
        yield ['1.2.3-pre', '1.2.3-beta1'];
        yield ['1.2.3pre2', '1.2.3-beta2'];
        yield ['1.2.3dev', '1.2.3-dev'];
        yield ['1.2.3-dev', '1.2.3-dev'];
        yield ['1.2.3+build2012', '1.2.3-patch2012'];
        yield ['1.2.3-build2012', '1.2.3-patch2012'];
        yield ['1.2.3+build.2012', '1.2.3-patch.2012'];
        yield ['1.2.3-build.2012', '1.2.3-patch.2012'];
        yield ['1.3.0–rc30.79', '1.3.0-RC30.79'];
        yield ['1.2.3-SNAPSHOT', '1.2.3-dev'];
        yield ['1.2.3alpha-foo', '1.2.3-alpha1'];
        yield ['1.2.3-1alpha', '1.2.3-patch1'];
        yield ['1.2.3rc.2foo', '1.2.3-RC2'];
        yield ['1.2.3-20123131.3246', '1.2.3-patch20123131.3246'];
        yield ['1.x.x-dev', '1.x-dev'];
        yield ['1.x.x.x.x-dev', '1.x-dev'];
        yield ['20170124.0.0', '20170124.000000'];
        yield ['20170124.1.0', '20170124.001000'];
        yield ['20170124.1.1', '20170124.001001'];
        yield ['20170124.100.200', '20170124.100200'];
        yield ['20170124.0', '20170124.000000'];
        yield ['20170124.1', '20170124.001000'];
        yield ['20170124', '20170124'];
        yield ['latest', 'default || *'];
        yield [null, '*'];
        yield ['', '*'];
    }
}
