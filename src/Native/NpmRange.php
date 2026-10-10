<?php

declare(strict_types=1);

namespace Foxy\Native;

use Composer\Pcre\Preg;
use Composer\Semver\Constraint\{Constraint, ConstraintInterface};
use Composer\Semver\VersionParser;
use Foxy\Exception\{Message, RuntimeException};
use Stringable;
use UnexpectedValueException;

use function explode;
use function implode;
use function in_array;
use function str_replace;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Represents an npm version range evaluated with npm semantics on top of Composer's constraint engine.
 *
 * Each `||` branch is rewritten into a Composer constraint (bare partials become wildcards, `~M.m` keeps the minor,
 * partial `>` and `<=` bounds are bumped, hyphen ranges become `>=` and `<=` pairs) and a prerelease version matches
 * a branch only when a comparator of that branch carries a prerelease on the same `major.minor.patch` tuple.
 * Wildcard majors with an operator (`>=*`) and prerelease tags Composer cannot normalize (`1.0.0-next.1`) are rejected.
 *
 * @see https://github.com/npm/node-semver#ranges
 */
final readonly class NpmRange implements Stringable
{
    private const string COMPARATOR = '/^(?:([<>]=?|[\^~])|=)?v?'
        . '(?:[xX*](?:\.[xX*]){0,2}|(\d+)(?:\.(?:[xX*](?:\.[xX*])?|'
        . '(\d+)(?:\.(?:[xX*]|(\d+)(-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?))?))?)$/';
    private const string HYPHEN = '/^(\S+)\s+-\s+(\S+)$/';
    private const string OPERATOR_SPACE = '/([<>]=?|[=^~])\s+/';
    private const string PRERELEASE = '/^([^+-]*)-/';

    /**
     * @param string $range Trimmed npm range as written.
     * @param list<array{constraint: ConstraintInterface, prereleases: list<string>}> $branches Composer constraint
     * of each `||` branch with the `major.minor.patch` tuples its prerelease comparators allow.
     */
    private function __construct(private string $range, private array $branches) {}

    /**
     * Returns the original npm range, trimmed.
     */
    public function __toString(): string
    {
        return $this->range;
    }

    /**
     * Parses an npm range.
     *
     * @param string $range npm range, such as `^1.2.3`, `1.x || >=2.1 <3`, or `1.2 - 2.3`.
     * @param string $package Package name used in the error message.
     *
     * @throws RuntimeException if the range is not valid npm syntax or cannot be expressed as a Composer constraint.
     */
    public static function parse(string $range, string $package): self
    {
        $range = trim($range);
        $parser = new VersionParser();
        $branches = [];

        foreach (explode('||', $range) as $branch) {
            $comparators = [];
            $prereleases = [];

            foreach (self::comparators(trim($branch)) as $comparator) {
                if (!Preg::isMatch(self::COMPARATOR, $comparator, $parts)) {
                    throw self::invalid($range, $package);
                }

                [, $operator, $major, $minor, $patch, $prerelease] = $parts;

                $comparators[] = self::rewriteComparator(
                    (string) $operator,
                    $major,
                    $minor,
                    $patch,
                    (string) $prerelease,
                );

                if (null !== $prerelease) {
                    $prereleases[] = "{$major}.{$minor}.{$patch}";
                }
            }

            try {
                $constraint = $parser->parseConstraints([] === $comparators ? '*' : implode(' ', $comparators));
            } catch (UnexpectedValueException) {
                throw self::invalid($range, $package);
            }

            $branches[] = ['constraint' => $constraint, 'prereleases' => $prereleases];
        }

        return new self($range, $branches);
    }

    /**
     * Returns whether a version satisfies the range.
     *
     * Build metadata is ignored; a version Composer cannot normalize never satisfies the range.
     *
     * @param string $version Version as published in the registry, such as `1.2.3` or `1.0.0-beta.1`.
     */
    public function satisfies(string $version): bool
    {
        try {
            $provider = new Constraint('==', (new VersionParser())->normalize($version));
        } catch (UnexpectedValueException) {
            return false;
        }

        $tuple = self::prereleaseTuple($version);

        foreach ($this->branches as $branch) {
            if (
                $branch['constraint']->matches($provider)
                && (null === $tuple || in_array($tuple, $branch['prereleases'], true))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the partial version right above `major[.minor]` (`1` -> `2`, `1.2` -> `1.3`) for an exclusive bound.
     */
    private static function bump(int $major, int|null $minor): string
    {
        return null === $minor ? (string) ($major + 1) : "{$major}." . ($minor + 1);
    }

    /**
     * Returns the comparators of a branch; a hyphen range `A - B` becomes `>=A` and `<=B`.
     *
     * @return list<string>
     */
    private static function comparators(string $branch): array
    {
        if (Preg::isMatchStrictGroups(self::HYPHEN, $branch, $bounds)) {
            return [">={$bounds[1]}", "<={$bounds[2]}"];
        }

        return Preg::split(
            '/\s+/',
            Preg::replace(self::OPERATOR_SPACE, '$1', str_replace('~>', '~', $branch)),
            flags: PREG_SPLIT_NO_EMPTY,
        );
    }

    private static function invalid(string $range, string $package): RuntimeException
    {
        return new RuntimeException(Message::NATIVE_RANGE_INVALID->getMessage($range, $package));
    }

    /**
     * Returns the `major.minor.patch` part of a prerelease version, or `null` when the version is not a prerelease.
     */
    private static function prereleaseTuple(string $version): string|null
    {
        return Preg::isMatchStrictGroups(self::PRERELEASE, $version, $match) ? $match[1] : null;
    }

    /**
     * Returns the Composer constraint equivalent to one npm comparator.
     *
     * @param string $operator npm operator (`''`, `<`, `<=`, `>`, `>=`, `^`, `~`).
     * @param string|null $major Major version, or `null` for a wildcard.
     * @param string|null $minor Minor version, or `null` for a wildcard or an absent part.
     * @param string|null $patch Patch version, or `null` for a wildcard or an absent part.
     * @param string $prerelease Prerelease suffix including its leading `-`, or `''`.
     */
    private static function rewriteComparator(
        string $operator,
        string|null $major,
        string|null $minor,
        string|null $patch,
        string $prerelease,
    ): string {
        if (null === $major) {
            return "{$operator}*";
        }

        if (null !== $patch) {
            return "{$operator}{$major}.{$minor}.{$patch}{$prerelease}";
        }

        $version = null === $minor ? $major : "{$major}.{$minor}";

        return match ($operator) {
            '' => "{$version}.*",
            '~' => null === $minor ? "~{$version}" : "~{$version}.0",
            '>' => '>=' . self::bump((int) $major, null === $minor ? null : (int) $minor),
            '<=' => '<' . self::bump((int) $major, null === $minor ? null : (int) $minor),
            default => "{$operator}{$version}",
        };
    }
}
