<?php

declare(strict_types=1);

namespace Foxy\Tests\Converter;

use Foxy\Converter\{SemverConverter, SemverUtil, VersionConverterInterface};
use Foxy\Tests\Provider\SemverConverterProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

use function in_array;
use function preg_match;

/**
 * Unit tests for {@see SemverConverter} conversion of semantic versions to Composer versions.
 *
 * {@see SemverConverterProvider} for test case data providers.
 */
final class SemverConverterTest extends TestCase
{
    private VersionConverterInterface|null $converter = null;

    #[DataProviderExternal(SemverConverterProvider::class, 'versions')]
    public function testConverter(string|null $semver, string $composer): void
    {
        self::assertSame(
            $composer,
            $this->converter->convertVersion($semver),
            'Converted version must match the expected Composer constraint.',
        );

        if (1 !== preg_match('/^[a-z]+$/i', (string) $semver) && !in_array($semver, [null, ''], true)) {
            self::assertSame(
                "v{$composer}",
                $this->converter->convertVersion("v{$semver}"),
                'Version conversion must preserve the `v` prefix.',
            );
        }
    }

    public function testCreatePatternIsPublicAndMatchesVersionPrefixes(): void
    {
        $pattern = SemverUtil::createPattern('[a-z]+');

        self::assertSame(
            1,
            preg_match($pattern, '1.2.3beta'),
            'Pattern must match a version with a prerelease suffix.',
        );
        self::assertSame(
            0,
            preg_match($pattern, 'beta1.2.3'),
            'Pattern must reject a prerelease prefix.',
        );
    }

    protected function setUp(): void
    {
        $this->converter = new SemverConverter();
    }

    protected function tearDown(): void
    {
        $this->converter = null;
    }
}
