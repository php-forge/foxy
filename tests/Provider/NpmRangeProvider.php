<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Native\NpmRangeTest} test cases.
 */
final class NpmRangeProvider
{
    /**
     * Candidate versions evaluated against every range, releases and prereleases mixed.
     */
    public const array CANDIDATES = [
        '0.0.3', '0.0.4', '0.1.0', '0.2.3', '0.2.9', '0.3.0', '1.0.0', '1.0.0-alpha.1', '1.0.0-beta.1', '1.0.0-rc.1',
        '1.0.1', '1.2.0', '1.2.3', '1.2.3-beta.2', '1.2.4', '1.3.0', '1.9.9', '2.0.0', '2.0.0-beta.1', '2.3.4', '2.3.5',
        '2.4.0', '3.0.0', '10.0.0',
    ];

    /**
     * Every candidate that is not a prerelease.
     */
    private const array RELEASES = [
        '0.0.3', '0.0.4', '0.1.0', '0.2.3', '0.2.9', '0.3.0', '1.0.0', '1.0.1', '1.2.0', '1.2.3', '1.2.4', '1.3.0',
        '1.9.9', '2.0.0', '2.3.4', '2.3.5', '2.4.0', '3.0.0', '10.0.0',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'bare caret' => ['^'];
        yield 'bare greater-than' => ['>'];
        yield 'bare tilde' => ['~'];
        yield 'comma separated comparators' => ['>=1.2.3, <2.0.0'];
        yield 'dist-tag latest' => ['latest'];
        yield 'dist-tag next' => ['next'];
        yield 'double greater-than' => ['>>1'];
        yield 'file protocol' => ['file:../x'];
        yield 'five version components' => ['1.2.3.4.5'];
        yield 'git url' => ['git+https://x'];
        yield 'hyphen without lower bound' => ['- 2.0.0'];
        yield 'hyphen without upper bound' => ['1.2.3 -'];
        yield 'invalid second branch' => ['^1.2.3 || latest'];
        yield 'not-equal operator' => ['!=1.2.3'];
        yield 'npm alias' => ['npm:foo@1'];
        yield 'operator on a wildcard major (deviation: npm reads it as any version)' => ['>=*'];
        yield 'prerelease tag Composer cannot normalize (deviation: npm accepts it)' => ['^1.0.0-next.1'];
        yield 'workspace protocol' => ['workspace:*'];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function matchingVersions(): iterable
    {
        yield 'asterisk' => [
            '*',
            self::RELEASES,
        ];
        yield 'bare major' => [
            '1',
            ['1.0.0', '1.0.1', '1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'bare major minor' => [
            '1.2',
            ['1.2.0', '1.2.3', '1.2.4'],
        ];
        yield 'build metadata on caret' => [
            '^1.2.3+build.5',
            ['1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'build metadata on exact version' => [
            '1.2.3+build.1',
            ['1.2.3'],
        ];
        yield 'build metadata on greater or equal' => [
            '>=1.0.0+build.1',
            [
                '1.0.0', '1.0.1', '1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9', '2.0.0', '2.3.4', '2.3.5',
                '2.4.0', '3.0.0', '10.0.0',
            ],
        ];
        yield 'caret followed by a space' => [
            '^ 1.2.3',
            ['1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'caret major' => [
            '^1',
            ['1.0.0', '1.0.1', '1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'caret major minor' => [
            '^1.2',
            ['1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'caret version' => [
            '^1.2.3',
            ['1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'caret x-range major' => [
            '^1.x',
            ['1.0.0', '1.0.1', '1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'caret x-range minor' => [
            '^1.2.x',
            ['1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'caret zero' => [
            '^0',
            ['0.0.3', '0.0.4', '0.1.0', '0.2.3', '0.2.9', '0.3.0'],
        ];
        yield 'caret zero major' => [
            '^0.2.3',
            ['0.2.3', '0.2.9'],
        ];
        yield 'caret zero major and minor' => [
            '^0.0.3',
            ['0.0.3'],
        ];
        yield 'caret zero zero' => [
            '^0.0',
            ['0.0.3', '0.0.4'],
        ];
        yield 'empty string' => [
            '',
            self::RELEASES,
        ];
        yield 'equals bare major' => [
            '=1',
            ['1.0.0', '1.0.1', '1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'equals version' => [
            '=1.2.3',
            ['1.2.3'],
        ];
        yield 'exact version' => [
            '1.2.3',
            ['1.2.3'],
        ];
        yield 'greater or equal followed by a space' => [
            '>= 1.2.3',
            ['1.2.3', '1.2.4', '1.3.0', '1.9.9', '2.0.0', '2.3.4', '2.3.5', '2.4.0', '3.0.0', '10.0.0'],
        ];
        yield 'greater or equal major minor' => [
            '>=1.2',
            ['1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9', '2.0.0', '2.3.4', '2.3.5', '2.4.0', '3.0.0', '10.0.0'],
        ];
        yield 'greater or equal version' => [
            '>=1.2.3',
            ['1.2.3', '1.2.4', '1.3.0', '1.9.9', '2.0.0', '2.3.4', '2.3.5', '2.4.0', '3.0.0', '10.0.0'],
        ];
        yield 'greater than major' => [
            '>1',
            ['2.0.0', '2.3.4', '2.3.5', '2.4.0', '3.0.0', '10.0.0'],
        ];
        yield 'greater than major minor' => [
            '>1.2',
            ['1.3.0', '1.9.9', '2.0.0', '2.3.4', '2.3.5', '2.4.0', '3.0.0', '10.0.0'],
        ];
        yield 'hyphen range with full bounds' => [
            '1.2.3 - 2.3.4',
            ['1.2.3', '1.2.4', '1.3.0', '1.9.9', '2.0.0', '2.3.4'],
        ];
        yield 'hyphen range with major upper bound' => [
            '1.2.3 - 2',
            ['1.2.3', '1.2.4', '1.3.0', '1.9.9', '2.0.0', '2.3.4', '2.3.5', '2.4.0'],
        ];
        yield 'hyphen range with partial bounds' => [
            '1.2 - 2.3',
            ['1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9', '2.0.0', '2.3.4', '2.3.5'],
        ];
        yield 'hyphen range with x-range upper bound' => [
            '1.2.3 - 2.x',
            ['1.2.3', '1.2.4', '1.3.0', '1.9.9', '2.0.0', '2.3.4', '2.3.5', '2.4.0'],
        ];
        yield 'intersection of greater and less' => [
            '>1.2.3 <2',
            ['1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'intersection of two exact versions' => [
            '1.2.3 1.2.4',
            [],
        ];
        yield 'less or equal major' => [
            '<=1',
            [
                '0.0.3', '0.0.4', '0.1.0', '0.2.3', '0.2.9', '0.3.0', '1.0.0', '1.0.1', '1.2.0', '1.2.3',
                '1.2.4', '1.3.0', '1.9.9',
            ],
        ];
        yield 'less or equal major minor' => [
            '<=1.2',
            ['0.0.3', '0.0.4', '0.1.0', '0.2.3', '0.2.9', '0.3.0', '1.0.0', '1.0.1', '1.2.0', '1.2.3', '1.2.4'],
        ];
        yield 'less than major minor' => [
            '<1.2',
            ['0.0.3', '0.0.4', '0.1.0', '0.2.3', '0.2.9', '0.3.0', '1.0.0', '1.0.1'],
        ];
        yield 'lowercase x' => [
            'x',
            self::RELEASES,
        ];
        yield 'prerelease caret' => [
            '^1.0.0-beta.1',
            ['1.0.0', '1.0.0-beta.1', '1.0.0-rc.1', '1.0.1', '1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'prerelease exact version' => [
            '1.0.0-beta.1',
            ['1.0.0-beta.1'],
        ];
        yield 'prerelease interval below its release (deviation: npm alpha.1 beta.1 rc.1, ours empty)' => [
            '>=1.0.0-alpha.1 <1.0.0',
            [],
        ];
        yield 'prerelease tilde' => [
            '~1.2.3-beta.2',
            ['1.2.3', '1.2.3-beta.2', '1.2.4'],
        ];
        yield 'tilde followed by a space' => [
            '~ 1.2.3',
            ['1.2.3', '1.2.4'],
        ];
        yield 'tilde greater-than alias' => [
            '~>1.2',
            ['1.2.0', '1.2.3', '1.2.4'],
        ];
        yield 'tilde major' => [
            '~1',
            ['1.0.0', '1.0.1', '1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'tilde major minor' => [
            '~1.2',
            ['1.2.0', '1.2.3', '1.2.4'],
        ];
        yield 'tilde version' => [
            '~1.2.3',
            ['1.2.3', '1.2.4'],
        ];
        yield 'tilde x-range minor' => [
            '~1.2.x',
            ['1.2.0', '1.2.3', '1.2.4'],
        ];
        yield 'tilde zero' => [
            '~0',
            ['0.0.3', '0.0.4', '0.1.0', '0.2.3', '0.2.9', '0.3.0'],
        ];
        yield 'tilde zero major minor' => [
            '~0.2',
            ['0.2.3', '0.2.9'],
        ];
        yield 'tilde zero major version' => [
            '~0.2.3',
            ['0.2.3', '0.2.9'],
        ];
        yield 'union of comparators' => [
            '<2 || >=3',
            [
                '0.0.3', '0.0.4', '0.1.0', '0.2.3', '0.2.9', '0.3.0', '1.0.0', '1.0.1', '1.2.0', '1.2.3',
                '1.2.4', '1.3.0', '1.9.9', '3.0.0', '10.0.0',
            ],
        ];
        yield 'union of hyphen range and x-range' => [
            '1.2.3 - 2.3.4 || 3.x',
            ['1.2.3', '1.2.4', '1.3.0', '1.9.9', '2.0.0', '2.3.4', '3.0.0'],
        ];
        yield 'union of version and caret' => [
            '1.2.3 || ^2.0.0',
            ['1.2.3', '2.0.0', '2.3.4', '2.3.5', '2.4.0'],
        ];
        yield 'union with an empty branch' => [
            '1.2.3 || ',
            self::RELEASES,
        ];
        yield 'uppercase X' => [
            'X',
            self::RELEASES,
        ];
        yield 'v-prefixed bare major' => [
            'v1',
            ['1.0.0', '1.0.1', '1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'v-prefixed version' => [
            'v1.2.3',
            ['1.2.3'],
        ];
        yield 'whitespace only' => [
            '   ',
            self::RELEASES,
        ];
        yield 'x-range major' => [
            '1.x',
            ['1.0.0', '1.0.1', '1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'x-range major uppercase' => [
            '1.X',
            ['1.0.0', '1.0.1', '1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'x-range major with two wildcards' => [
            '1.x.x',
            ['1.0.0', '1.0.1', '1.2.0', '1.2.3', '1.2.4', '1.3.0', '1.9.9'],
        ];
        yield 'x-range minor' => [
            '1.2.x',
            ['1.2.0', '1.2.3', '1.2.4'],
        ];
        yield 'x-range minor with asterisk' => [
            '1.2.*',
            ['1.2.0', '1.2.3', '1.2.4'],
        ];
    }
}
