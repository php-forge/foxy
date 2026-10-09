<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Audit\AuditNormalizerTest} test cases.
 *
 * Provides representative input/output pairs for CVE and GHSA identifiers and string lists.
 */
final class AuditNormalizerProvider
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function cveIds(): iterable
    {
        yield 'five digit sequence' => ['CVE-2021-123456', true];
        yield 'lowercase prefix' => ['cve-2021-23337', true];
        yield 'short sequence' => ['CVE-2021-233', false];
        yield 'surrounding text prefix' => ['see CVE-2021-23337', false];
        yield 'surrounding text suffix' => ['CVE-2021-23337 fixed', false];
        yield 'uppercase prefix' => ['CVE-2021-23337', true];
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function ghsaIds(): iterable
    {
        yield 'advisory URL' => ['https://github.com/advisories/GHSA-35jh-r3h4-6jhm', null];
        yield 'lowercase prefix' => ['ghsa-35jh-r3h4-6jhm', 'GHSA-35jh-r3h4-6jhm'];
        yield 'short segment' => ['GHSA-35j-r3h4-6jhm', null];
        yield 'surrounding text prefix' => ['prefix-GHSA-35jh-r3h4-6jhm', null];
        yield 'surrounding text suffix' => ['GHSA-35jh-r3h4-6jhm-suffix', null];
        yield 'trailing slash' => ['GHSA-35jh-r3h4-6jhm/', null];
        yield 'uppercase segments' => ['GHSA-35JH-R3H4-6JHM', 'GHSA-35jh-r3h4-6jhm'];
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function ghsaReferences(): iterable
    {
        yield 'advisory URL' => [
            'https://github.com/advisories/GHSA-35JH-R3H4-6JHM',
            'GHSA-35jh-r3h4-6jhm',
        ];
        yield 'advisory URL with double trailing slash' => [
            'https://github.com/advisories/GHSA-35jh-r3h4-6jhm//',
             null,
        ];
        yield 'advisory URL with trailing slash' => [
            'https://github.com/advisories/GHSA-35jh-r3h4-6jhm/',
            'GHSA-35jh-r3h4-6jhm',
        ];
        yield 'bare identifier' => [
            'ghsa-35JH-r3h4-6jhm',
            'GHSA-35jh-r3h4-6jhm',
        ];
        yield 'bare identifier with trailing slash' => [
            'GHSA-35jh-r3h4-6jhm/',
            'GHSA-35jh-r3h4-6jhm',
        ];
        yield 'empty string' => [
            '',
            null,
        ];
        yield 'foreign advisory URL' => [
            'https://security.example.test/advisories/GHSA-35jh-r3h4-6jhm',
            null,
        ];
        yield 'uppercase advisory URL' => [
            'HTTPS://GITHUB.COM/ADVISORIES/GHSA-35jh-r3h4-6jhm',
            'GHSA-35jh-r3h4-6jhm',
        ];
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function stringLists(): iterable
    {
        yield 'duplicates' => [['b', 'a', 'b'], ['a', 'b']];
        yield 'empty list' => [[], []];
        yield 'sorted unique values' => [['a', 'b'], ['a', 'b']];
        yield 'unsorted unique values' => [['c', 'a', 'b'], ['a', 'b', 'c']];
    }
}
