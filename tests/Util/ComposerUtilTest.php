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

namespace Foxy\Tests\Util;

use Foxy\Util\ComposerUtil;

/**
 * Tests for composer util.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 *
 * @internal
 */
final class ComposerUtilTest extends \PHPUnit\Framework\TestCase
{
    public static function getValidateVersionData(): array
    {
        return [
            ['@package_version@', '^1.5.0', true],
            ['@package_version@', '^1.5.0|^2.0.0', true],
            ['d173af2d7ac1408655df2cf6670ea0262e06d137', '^1.5.0|^2.0.0', true],
            ['1.6.0', '^1.5.0', true],
            ['1.5.1', '^1.5.0', true],
            ['1.5.0', '^1.5.0', true],
            ['1.5.0', '^1.5.0|^2.0.0', true],
            ['1.5.0', '^1.5.1', false],
            ['1.0.0', '^1.5.0', false],
        ];
    }

    /**
     * @dataProvider getValidateVersionData
     */
    public function testValidateVersion(string $composerVersion, string $requiredVersion, bool $valid): void
    {
        if ($valid) {
            $this->assertTrue(true, 'Composer\'s version is valid');
        } else {
            $this->expectException(\Foxy\Exception\RuntimeException::class);
            $this->expectExceptionMessageMatches(
                '/Foxy requires the Composer\'s minimum version "([\d\.^|, ]+)", current version is "([\d\.]+)"/'
            );
        }

        ComposerUtil::validateVersion($requiredVersion, $composerVersion);
    }
}
