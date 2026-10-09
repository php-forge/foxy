<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Audit\GitHubAdvisoryCveResolverTest} test cases.
 */
final class GitHubAdvisoryCveResolverProvider
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function ghsaIdsWithSurroundingText(): iterable
    {
        yield 'prefix' => ['prefix-GHSA-35jh-r3h4-6jhm'];
        yield 'suffix' => ['GHSA-35jh-r3h4-6jhm-suffix'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'not an array' => ['"invalid"'];
        yield 'not a list' => ['{"type":"CVE","value":"CVE-2021-23337"}'];
    }
}
