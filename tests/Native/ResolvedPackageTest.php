<?php

declare(strict_types=1);

namespace Foxy\Tests\Native;

use Foxy\Native\{PackageVersion, ResolvedPackage};
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ResolvedPackage} creation from registry and local sources and its lock file entry.
 */
final class ResolvedPackageTest extends TestCase
{
    public function testFromLocalKeepsPathAsWritten(): void
    {
        $package = ResolvedPackage::fromLocal('@composer-asset/acme--theme', '1.0.0', './vendor/acme/theme');

        self::assertSame(
            '@composer-asset/acme--theme',
            $package->name,
            'Name must be kept.',
        );
        self::assertSame(
            '1.0.0',
            $package->version,
            'Version must be kept.',
        );
        self::assertSame(
            './vendor/acme/theme',
            $package->path,
            'Relative path must be kept as written.',
        );
        self::assertNull(
            $package->resolved,
            'A local package has no tarball URL.',
        );
        self::assertNull(
            $package->integrity,
            'A local package has no integrity value.',
        );
        self::assertTrue(
            $package->isLocal(),
            'A package with a path is local.',
        );
        self::assertSame(
            ['version' => '1.0.0', 'file' => './vendor/acme/theme'],
            $package->toLock(),
            'Lock entry must carry the version and the file path.',
        );
    }

    public function testFromRegistryCopiesTarballAndIntegrity(): void
    {
        $package = ResolvedPackage::fromRegistry(
            new PackageVersion(
                'bootstrap',
                '5.3.8',
                'https://registry.npmjs.org/bootstrap/-/bootstrap-5.3.8.tgz',
                'sha512-BOOTSTRAP',
                ['jquery' => '^3.7.1'],
                deprecated: 'Superseded.',
            ),
        );

        self::assertSame(
            'bootstrap',
            $package->name,
            'Name must be copied.',
        );
        self::assertSame(
            '5.3.8',
            $package->version,
            'Version must be copied.',
        );
        self::assertSame(
            'https://registry.npmjs.org/bootstrap/-/bootstrap-5.3.8.tgz',
            $package->resolved,
            'Tarball URL must be copied.',
        );
        self::assertSame(
            'sha512-BOOTSTRAP',
            $package->integrity,
            'Integrity must be copied.',
        );
        self::assertNull(
            $package->path,
            'A registry package has no path.',
        );
        self::assertFalse(
            $package->isLocal(),
            'A package without a path is not local.',
        );
        self::assertSame(
            [
                'version' => '5.3.8',
                'resolved' => 'https://registry.npmjs.org/bootstrap/-/bootstrap-5.3.8.tgz',
                'integrity' => 'sha512-BOOTSTRAP',
            ],
            $package->toLock(),
            'Lock entry must carry the version, the tarball URL, and the integrity.',
        );
    }
}
