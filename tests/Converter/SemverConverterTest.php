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

namespace Foxy\Tests\Converter;

use Foxy\Converter\SemverConverter;
use Foxy\Converter\SemverUtil;
use Foxy\Converter\VersionConverterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the conversion of Semver syntax to composer syntax.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class SemverConverterTest extends TestCase
{
    private VersionConverterInterface|null $converter = null;

    protected function setUp(): void
    {
        $this->converter = new SemverConverter();
    }

    protected function tearDown(): void
    {
        $this->converter = null;
    }

    /**
     * @dataProvider getTestVersions
     */
    public function testConverter(string|null $semver, string $composer): void
    {
        $this->assertEquals($composer, $this->converter->convertVersion($semver));

        if (!\ctype_alpha((string) $semver) && !\in_array($semver, [null, ''], true)) {
            $this->assertEquals('v' . $composer, $this->converter->convertVersion('v' . $semver));
        }
    }

    public static function getTestVersions(): array
    {
        return [
            ['1.2.3', '1.2.3'],
            ['1.2.3alpha', '1.2.3-alpha1'],
            ['1.2.3-alpha', '1.2.3-alpha1'],
            ['1.2.3a', '1.2.3-alpha1'],
            ['1.2.3a1', '1.2.3-alpha1'],
            ['1.2.3-a', '1.2.3-alpha1'],
            ['1.2.3-a1', '1.2.3-alpha1'],
            ['1.2.3b', '1.2.3-beta1'],
            ['1.2.3b1', '1.2.3-beta1'],
            ['1.2.3-b', '1.2.3-beta1'],
            ['1.2.3-b1', '1.2.3-beta1'],
            ['1.2.3beta', '1.2.3-beta1'],
            ['1.2.3-beta', '1.2.3-beta1'],
            ['1.2.3beta1', '1.2.3-beta1'],
            ['1.2.3-beta1', '1.2.3-beta1'],
            ['1.2.3rc1', '1.2.3-RC1'],
            ['1.2.3-rc1', '1.2.3-RC1'],
            ['1.2.3rc2', '1.2.3-RC2'],
            ['1.2.3-rc2', '1.2.3-RC2'],
            ['1.2.3rc.2', '1.2.3-RC.2'],
            ['1.2.3-rc.2', '1.2.3-RC.2'],
            ['1.2.3+0', '1.2.3-patch0'],
            ['1.2.3-0', '1.2.3-patch0'],
            ['1.2.3pre', '1.2.3-beta1'],
            ['1.2.3-pre', '1.2.3-beta1'],
            ['1.2.3dev', '1.2.3-dev'],
            ['1.2.3-dev', '1.2.3-dev'],
            ['1.2.3+build2012', '1.2.3-patch2012'],
            ['1.2.3-build2012', '1.2.3-patch2012'],
            ['1.2.3+build.2012', '1.2.3-patch.2012'],
            ['1.2.3-build.2012', '1.2.3-patch.2012'],
            ['1.3.0–rc30.79', '1.3.0-RC30.79'],
            ['1.2.3-SNAPSHOT', '1.2.3-dev'],
            ['1.2.3-20123131.3246', '1.2.3-patch20123131.3246'],
            ['1.x.x-dev', '1.x-dev'],
            ['20170124.0.0', '20170124.000000'],
            ['20170124.1.0', '20170124.001000'],
            ['20170124.1.1', '20170124.001001'],
            ['20170124.100.200', '20170124.100200'],
            ['20170124.0', '20170124.000000'],
            ['20170124.1', '20170124.001000'],
            ['20170124', '20170124'],
            ['latest', 'default || *'],
            [null, '*'],
            ['', '*'],
        ];
    }
}
