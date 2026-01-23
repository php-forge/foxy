<?php

declare(strict_types=1);

namespace Foxy\Tests\Util;

use Foxy\Exception\RuntimeException;
use Foxy\Util\ComposerUtil;
use PHPUnit\Framework\TestCase;

final class ComposerUtilTest extends TestCase
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
            self::assertTrue(true, 'Composer\'s version is valid');
        } else {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches(
                '/Foxy requires the Composer\'s minimum version "([\d\.^|, ]+)", current version is "([\d\.]+)"/',
            );
        }

        ComposerUtil::validateVersion($requiredVersion, $composerVersion);
    }
}
