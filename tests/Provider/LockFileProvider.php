<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use function json_encode;

/**
 * Data provider for {@see \Foxy\Tests\Native\LockFileTest} test cases.
 */
final class LockFileProvider
{
    private const string INTEGRITY = 'sha512-Ab+/9w==';
    private const string RESOLVED = 'https://registry.npmjs.org/bootstrap/-/bootstrap-5.3.8.tgz';

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedLocks(): iterable
    {
        yield 'file is not a string' => [self::package(['version' => '1.0.0', 'file' => 5])];
        yield 'integrity has a prefix' => [self::registryPackage('x' . self::INTEGRITY)];
        yield 'integrity has an invalid character' => [self::registryPackage('sha512-Ab!w==')];
        yield 'integrity has no digest' => [self::registryPackage('sha512-')];
        yield 'integrity is missing' => [self::package(['version' => '5.3.8', 'resolved' => self::RESOLVED])];
        yield 'integrity uses an unknown algorithm' => [self::registryPackage('md5-Ab+/9w==')];
        yield 'invalid json' => ['{"requirements":'];
        yield 'json list' => ['[1, 2]'];
        yield 'json string' => ['"lock"'];
        yield 'package has neither resolved nor file' => [
            self::package(['version' => '5.3.8', 'integrity' => self::INTEGRITY]),
        ];
        yield 'package is not an object' => [self::package('5.3.8')];
        yield 'package version is missing' => [
            self::package(['resolved' => self::RESOLVED, 'integrity' => self::INTEGRITY]),
        ];
        yield 'package version is not a string' => [
            self::package(['version' => 5, 'resolved' => self::RESOLVED, 'integrity' => self::INTEGRITY]),
        ];
        yield 'packages is missing' => [self::encode(['requirements' => ['' => ['bootstrap' => '^5.3']]])];
        yield 'packages is not an object' => [self::encode(['requirements' => [], 'packages' => 'bootstrap'])];
        yield 'requirement spec is not a string' => [
            self::encode(['requirements' => ['' => ['bootstrap' => 5]], 'packages' => []]),
        ];
        yield 'requirement value is not a map' => [
            self::encode(['requirements' => ['' => 'bootstrap'], 'packages' => []]),
        ];
        yield 'requirements is missing' => [self::encode(['packages' => []])];
        yield 'requirements is not an object' => [self::encode(['requirements' => 'bootstrap', 'packages' => []])];
        yield 'resolved is not a string' => [
            self::package(['version' => '5.3.8', 'resolved' => 5, 'integrity' => self::INTEGRITY]),
        ];
    }

    private static function encode(mixed $data): string
    {
        return (string) json_encode($data);
    }

    private static function package(mixed $entry): string
    {
        return self::encode(
            ['requirements' => ['' => ['bootstrap' => '^5.3']], 'packages' => ['bootstrap' => $entry]],
        );
    }

    private static function registryPackage(string $integrity): string
    {
        return self::package(['version' => '5.3.8', 'resolved' => self::RESOLVED, 'integrity' => $integrity]);
    }
}
