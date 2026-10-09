<?php

declare(strict_types=1);

namespace Foxy\Tests\Util;

use Foxy\Exception\{Message, RuntimeException};
use Foxy\Tests\Provider\ComposerUtilProvider;
use Foxy\Util\ComposerUtil;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Unit tests for {@see ComposerUtil} Composer version validation.
 *
 * {@see ComposerUtilProvider} for test case data providers.
 */
final class ComposerUtilTest extends TestCase
{
    #[DataProviderExternal(ComposerUtilProvider::class, 'composerVersions')]
    public function testValidateVersion(string $composerVersion, string $requiredVersion, bool $valid): void
    {
        if ($valid) {
            $this->expectNotToPerformAssertions();
        } else {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                Message::UTIL_COMPOSER_VERSION_UNSUPPORTED->getMessage($requiredVersion, $composerVersion),
            );
        }

        ComposerUtil::validateVersion($requiredVersion, $composerVersion);
    }

    public function testValidateVersionRejectsHashWithPrefix(): void
    {
        $this->expectException(UnexpectedValueException::class);

        ComposerUtil::validateVersion(
            '^1.5.0|^2.0.0',
            'prefix-d173af2d7ac1408655df2cf6670ea0262e06d137',
        );
    }

    public function testValidateVersionRejectsHashWithSuffix(): void
    {
        $this->expectException(UnexpectedValueException::class);

        ComposerUtil::validateVersion(
            '^1.5.0|^2.0.0',
            'd173af2d7ac1408655df2cf6670ea0262e06d137-suffix',
        );
    }
}
