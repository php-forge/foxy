<?php

declare(strict_types=1);

namespace Foxy\Tests\Native;

use Foxy\Exception\RuntimeException;
use Foxy\Native\{PackageMetadata, PackageVersion};
use Foxy\Tests\Provider\PackageMetadataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function sprintf;

/**
 * Unit tests for {@see PackageMetadata} construction from decoded npm registry documents.
 *
 * {@see PackageMetadataProvider} for test case data providers.
 */
final class PackageMetadataTest extends TestCase
{
    private const string SHA1_OF_EMPTY = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
    private const string TARBALL = 'https://registry.npmjs.org/@acme/widgets/-/widgets-%s.tgz';

    public function testFromDocumentComputesSha1IntegrityFromShasum(): void
    {
        $metadata = PackageMetadata::fromDocument('@acme/widgets', $this->document());

        self::assertSame(
            'sha1-2jmj7l5rSw0yVb/vlWAYkK/YBwk=',
            $metadata->getVersion('1.0.0')?->integrity,
            'A missing integrity must fall back to the base64 shasum.',
        );
        self::assertSame(
            'sha1-2jmj7l5rSw0yVb/vlWAYkK/YBwk=',
            $metadata->getVersion('2.0.0')?->integrity,
            'An empty integrity must fall back to the base64 shasum.',
        );
        self::assertSame(
            'sha1-2jmj7l5rSw0yVb/vlWAYkK/YBwk=',
            $metadata->getVersion('3')?->integrity,
            'A non-string integrity must fall back to the base64 shasum.',
        );
        self::assertSame(
            'sha512-WIDGETS210',
            $metadata->getVersion('2.1.0')?->integrity,
            'A declared integrity must win over the shasum.',
        );
    }

    public function testFromDocumentDefaultsAbsentMembers(): void
    {
        $metadata = PackageMetadata::fromDocument('@acme/widgets', $this->document());

        $version = $metadata->getVersion('1.0.0');

        self::assertInstanceOf(
            PackageVersion::class,
            $version,
            'Version 1.0.0 must be present.',
        );
        self::assertSame(
            [],
            $version->dependencies,
            'A `null` dependency map must read as empty.',
        );
        self::assertSame(
            [],
            $version->peerDependencies,
            'An absent peer map must read as empty.',
        );
        self::assertSame(
            [],
            $version->optionalDependencies,
            'An absent optional map must read as empty.',
        );
        self::assertSame(
            [],
            $version->optionalPeerDependencies,
            'An absent peer meta must yield no optional peers.',
        );
        self::assertSame(
            [],
            PackageMetadata::fromDocument('@acme/widgets', ['versions' => []])->distTags,
            'Absent dist-tags must read as empty.',
        );
    }

    public function testFromDocumentKeepsDeprecationText(): void
    {
        $metadata = PackageMetadata::fromDocument('@acme/widgets', $this->document());

        self::assertSame(
            'Use 2.x instead.',
            $metadata->getVersion('1.0.0')?->deprecated,
            'The deprecation text must be kept.',
        );
        self::assertNull(
            $metadata->getVersion('2.0.0')?->deprecated,
            'An absent deprecation must read as `null`.',
        );
        self::assertNull(
            $metadata->getVersion('2.1.0')?->deprecated,
            'An empty deprecation must read as `null`.',
        );
        self::assertNull(
            $metadata->getVersion('3')?->deprecated,
            'A non-string deprecation must read as `null`.',
        );
    }

    public function testFromDocumentReadsDependencyMaps(): void
    {
        $version = PackageMetadata::fromDocument('@acme/widgets', $this->document())->getVersion('2.1.0');

        self::assertInstanceOf(
            PackageVersion::class,
            $version,
            'Version 2.1.0 must be present.',
        );
        self::assertSame(
            ['lodash' => '^4.17.21'],
            $version->dependencies,
            'Dependencies must be copied verbatim.',
        );
        self::assertSame(
            ['react' => '^18.0.0', 'react-dom' => '^18.0.0', 'vue' => '^3.4.0', 'svelte' => '^4.0.0'],
            $version->peerDependencies,
            'Peer dependencies must be copied verbatim.',
        );
        self::assertSame(
            ['fsevents' => '^2.3.0'],
            $version->optionalDependencies,
            'Optional dependencies must be copied verbatim.',
        );
        self::assertSame(
            ['react-dom'],
            $version->optionalPeerDependencies,
            'Only peers marked with a strict `true` optional flag are optional.',
        );
    }

    public function testFromDocumentReadsVersionsAndDistTags(): void
    {
        $metadata = PackageMetadata::fromDocument('@acme/widgets', $this->document());

        self::assertSame(
            '@acme/widgets',
            $metadata->name,
            'The package name must come from the argument.',
        );
        self::assertSame(
            ['latest' => '2.1.0', 'next' => '3.0.0-beta.1'],
            $metadata->distTags,
            'Dist-tags must be copied verbatim.',
        );
        self::assertSame(
            ['1.0.0', '2.0.0', '2.1.0', 3],
            array_keys($metadata->versions),
            'Unparsable version keys must be skipped and the rest kept in order.',
        );
        self::assertSame(
            '3',
            $metadata->getVersion('3')?->version,
            'Numeric version keys must be read as strings.',
        );

        $version = $metadata->getVersion('1.0.0');

        self::assertInstanceOf(
            PackageVersion::class,
            $version,
            'Version 1.0.0 must be present.',
        );
        self::assertSame(
            '@acme/widgets',
            $version->name,
            'Every version must carry the package name.',
        );
        self::assertSame(
            sprintf(self::TARBALL, '1.0.0'),
            $version->tarball,
            'The tarball URL must be copied verbatim.',
        );
    }

    public function testGetDistTagReturnsNullForUnknownTag(): void
    {
        $metadata = PackageMetadata::fromDocument('@acme/widgets', $this->document());

        self::assertSame(
            '3.0.0-beta.1',
            $metadata->getDistTag('next'),
            'A known tag must return its version.',
        );
        self::assertNull(
            $metadata->getDistTag('canary'),
            'An unknown tag must return `null`.',
        );
    }

    public function testGetVersionReturnsNullForUnknownVersion(): void
    {
        $metadata = PackageMetadata::fromDocument('@acme/widgets', $this->document());

        self::assertNull(
            $metadata->getVersion('9.9.9'),
            'An unknown version must return `null`.',
        );
        self::assertNull(
            $metadata->getVersion('not-a-version'),
            'A skipped version must return `null`.',
        );
    }

    #[DataProviderExternal(PackageMetadataProvider::class, 'malformedDocuments')]
    public function testThrowRuntimeExceptionForMalformedDocument(mixed $document, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        PackageMetadata::fromDocument('bootstrap', $document);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return [
            'name' => 'ignored/name',
            'dist-tags' => ['latest' => '2.1.0', 'next' => '3.0.0-beta.1'],
            'versions' => [
                'not-a-version' => 'skipped without validation',
                '1.0.0' => [
                    'version' => '9.9.9',
                    'dist' => ['tarball' => sprintf(self::TARBALL, '1.0.0'), 'shasum' => self::SHA1_OF_EMPTY],
                    'dependencies' => null,
                    'deprecated' => 'Use 2.x instead.',
                ],
                '2.0.0' => [
                    'dist' => [
                        'tarball' => sprintf(self::TARBALL, '2.0.0'),
                        'integrity' => '',
                        'shasum' => self::SHA1_OF_EMPTY,
                    ],
                ],
                '2.1.0' => [
                    'dist' => [
                        'tarball' => sprintf(self::TARBALL, '2.1.0'),
                        'integrity' => 'sha512-WIDGETS210',
                        'shasum' => self::SHA1_OF_EMPTY,
                    ],
                    'dependencies' => ['lodash' => '^4.17.21'],
                    'peerDependencies' => [
                        'react' => '^18.0.0',
                        'react-dom' => '^18.0.0',
                        'vue' => '^3.4.0',
                        'svelte' => '^4.0.0',
                    ],
                    'optionalDependencies' => ['fsevents' => '^2.3.0'],
                    'peerDependenciesMeta' => [
                        'react' => ['optional' => false],
                        'react-dom' => ['optional' => true],
                        'vue' => ['optional' => 'true'],
                        'svelte' => 'optional',
                    ],
                    'deprecated' => '',
                ],
                3 => [
                    'dist' => [
                        'tarball' => sprintf(self::TARBALL, '3.0.0'),
                        'integrity' => 512,
                        'shasum' => self::SHA1_OF_EMPTY,
                    ],
                    'deprecated' => true,
                ],
            ],
        ];
    }
}
