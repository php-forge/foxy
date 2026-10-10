<?php

declare(strict_types=1);

namespace Foxy\Native;

use Composer\IO\IOInterface;
use Composer\Semver\Semver;
use Foxy\Exception\{Message, RuntimeException};

use function array_map;
use function array_pop;
use function array_shift;
use function implode;
use function in_array;
use function ksort;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strval;

use const SORT_STRING;

/**
 * Resolves npm requirements into one version per package (flat `node_modules`) against an npm registry.
 *
 * Each constraint is recorded with the requirement that declared it. The highest version satisfying every constraint
 * of a name is selected; a later constraint the selection violates triggers a re-pick, which abandons the previous
 * version and retracts the constraints it declared, cascading to every selection left without constraints, which is
 * dropped until something requires it again. A selection is never upgraded because its constraints loosened, and
 * re-selecting an abandoned version is reported as a version conflict, so resolution always ends. Dependencies,
 * non-optional peer dependencies, and optional dependencies are followed; an optional constraint never blocks a
 * mandatory one: it is dropped with a warning instead, and an optional dependency that is unsatisfiable or rejects
 * the current selection is skipped with a warning. Packages no longer reachable from the requirements are dropped from the result.
 *
 * @phpstan-type Constraint array{range: NpmRange, source: string, optional: bool}
 */
final readonly class DependencyResolver
{
    private const string DEPRECATED = '<warning>The package "%s" (%s) is deprecated: %s</warning>';
    private const string OPTIONAL_SKIPPED = '<warning>The optional dependency "%s" (%s) was skipped: %s</warning>';

    /**
     * @param NpmRegistryInterface $registry Registry that provides the package metadata.
     * @param IOInterface $io Output that receives the deprecation and skipped-dependency warnings.
     */
    public function __construct(private NpmRegistryInterface $registry, private IOInterface $io) {}

    /**
     * Resolves the requirements and their transitive dependencies.
     *
     * @param list<Requirement> $requirements Registry requirements; `file:` entries never reach the resolver.
     *
     * @throws RuntimeException if a mandatory requirement uses an unsupported protocol, an invalid range, has no
     * version satisfying its mandatory constraints, would return to an abandoned version, or the registry fails.
     *
     * @return array<string, ResolvedPackage> Selected packages keyed and sorted by name.
     */
    public function resolve(array $requirements): array
    {
        $abandoned = [];
        $dropped = [];
        $metadata = [];
        $constraints = [];
        $selected = [];
        $queue = $requirements;

        while ([] !== $queue) {
            $requirement = array_shift($queue);

            if (isset($abandoned[$requirement->source]) || isset($dropped[$requirement->source])) {
                continue;
            }

            try {
                $version = $this->select($requirement, $metadata, $constraints, $selected, $abandoned);
            } catch (RuntimeException $exception) {
                if (!$requirement->optional) {
                    throw $exception;
                }

                $this->io->writeError(
                    sprintf(self::OPTIONAL_SKIPPED, $requirement->name, $requirement->spec, $exception->getMessage()),
                );

                continue;
            }

            if (null === $version) {
                continue;
            }

            $previous = $selected[$requirement->name] ?? null;
            $selected[$requirement->name] = $version;

            unset($dropped["{$version->name}@{$version->version}"]);

            if (null !== $version->deprecated) {
                $this->io->writeError(
                    sprintf(self::DEPRECATED, $version->name, $version->version, $version->deprecated),
                );
            }

            foreach (self::requirementsOf($version) as $dependency) {
                $queue[] = $dependency;
            }

            if (null !== $previous) {
                $source = "{$previous->name}@{$previous->version}";
                $abandoned[$source] = $previous;

                self::retract($source, $constraints, $selected, $dropped);
            }
        }

        $resolved = [];

        foreach (self::reachable($requirements, $selected) as $name => $version) {
            $resolved[$name] = ResolvedPackage::fromRegistry($version);
        }

        ksort($resolved, SORT_STRING);

        return $resolved;
    }

    /**
     * Returns the constraint list of a version conflict, such as `"^5.0" from root, "^4.0" from acme@1.0.0`.
     *
     * @param list<Constraint> $constraints
     */
    private static function describe(array $constraints): string
    {
        return implode(
            ', ',
            array_map(
                static fn(array $constraint): string => sprintf(
                    '"%s" from %s',
                    $constraint['range'],
                    '' === $constraint['source'] ? 'root' : $constraint['source'],
                ),
                $constraints,
            ),
        );
    }

    /**
     * Returns the highest version satisfying every range, or `null` when none does.
     *
     * @param list<Constraint> $constraints
     */
    private static function highest(PackageMetadata $metadata, array $constraints): PackageVersion|null
    {
        $versions = array_map(
            static fn(PackageVersion $version): string => $version->version,
            $metadata->versions,
        );

        foreach (Semver::rsort($versions) as $candidate) {
            foreach ($constraints as $constraint) {
                if (!$constraint['range']->satisfies($candidate)) {
                    continue 2;
                }
            }

            return $metadata->versions[$candidate];
        }

        return null;
    }

    /**
     * Returns whether a specification uses a protocol the native manager does not support (`npm:`, `git`, URLs,
     * `github:` shorthands, `user/repo`, `file:`, `workspace:`).
     */
    private static function isUnsupported(string $spec): bool
    {
        return str_contains($spec, ':')
            || str_contains($spec, '/')
            || str_contains($spec, '#')
            || str_starts_with($spec, 'git')
            || str_starts_with($spec, 'http');
    }

    /**
     * Returns the selections reachable from the requirements through the selected versions' dependencies.
     *
     * @param list<Requirement> $requirements
     * @param array<string, PackageVersion> $selected
     *
     * @return array<string, PackageVersion>
     */
    private static function reachable(array $requirements, array $selected): array
    {
        $reachable = [];
        $queue = $requirements;

        while ([] !== $queue) {
            $name = array_shift($queue)->name;

            if (isset($reachable[$name]) || !isset($selected[$name])) {
                continue;
            }

            $reachable[$name] = $selected[$name];

            foreach (self::requirementsOf($selected[$name]) as $dependency) {
                $queue[] = $dependency;
            }
        }

        return $reachable;
    }

    /**
     * Returns the requirements a version declares: dependencies, non-optional peers, and optional dependencies.
     *
     * An optional dependency overrides a dependency or a peer of the same name, as npm does.
     *
     * @return list<Requirement>
     */
    private static function requirementsOf(PackageVersion $version): array
    {
        $source = "{$version->name}@{$version->version}";

        $optionalPeers = array_map(strval(...), $version->optionalPeerDependencies);

        $requirements = [];

        foreach ($version->dependencies as $name => $spec) {
            if (!isset($version->optionalDependencies[$name])) {
                $requirements[] = new Requirement((string) $name, $spec, $source);
            }
        }

        foreach ($version->peerDependencies as $name => $spec) {
            if (!isset($version->optionalDependencies[$name]) && !in_array((string) $name, $optionalPeers, true)) {
                $requirements[] = new Requirement((string) $name, $spec, $source);
            }
        }

        foreach ($version->optionalDependencies as $name => $spec) {
            $requirements[] = new Requirement((string) $name, $spec, $source, true);
        }

        return $requirements;
    }

    /**
     * Removes the constraints declared by a replaced selection and, depth-first, those of every selection left without
     * constraints, which is unselected and recorded as dropped.
     *
     * @param string $source Replaced selection (`name@version`) whose constraints are retracted.
     * @param array<string, list<Constraint>> $constraints Constraints keyed by package name.
     * @param array<string, PackageVersion> $selected Current selections.
     * @param array<string, PackageVersion> $dropped Selections no longer required, keyed by `name@version`.
     */
    private static function retract(string $source, array &$constraints, array &$selected, array &$dropped): void
    {
        $sources = [$source];

        while ([] !== $sources) {
            $retracted = array_pop($sources);

            foreach ($constraints as $name => $list) {
                $kept = [];

                foreach ($list as $constraint) {
                    if ($constraint['source'] !== $retracted) {
                        $kept[] = $constraint;
                    }
                }

                $constraints[$name] = $kept;

                if ([] === $kept && isset($selected[$name])) {
                    $key = "{$name}@{$selected[$name]->version}";
                    $dropped[$key] = $selected[$name];
                    $sources[] = $key;

                    unset($selected[$name]);
                }
            }
        }
    }

    /**
     * Records the requirement's constraint and returns the version to select, or `null` when the current selection
     * already satisfies it.
     *
     * An optional requirement never changes an existing selection. When no version satisfies every constraint of a
     * mandatory requirement, the version satisfying the mandatory constraints is selected and each optional constraint
     * it violates is dropped with a warning.
     *
     * @param array<string, PackageMetadata> $metadata Metadata fetched so far, keyed by name.
     * @param array<string, list<Constraint>> $constraints Constraints recorded so far, keyed by name.
     * @param array<string, PackageVersion> $selected Current selections.
     * @param array<string, PackageVersion> $abandoned Selections replaced by a re-pick, keyed by `name@version`.
     *
     * @throws RuntimeException if the specification is unsupported or invalid, the registry fails, an optional
     * requirement rejects the current selection, no version satisfies the constraints, or the version found was
     * abandoned before.
     */
    private function select(
        Requirement $requirement,
        array &$metadata,
        array &$constraints,
        array $selected,
        array $abandoned,
    ): PackageVersion|null {
        $name = $requirement->name;
        $spec = $requirement->spec;

        if (self::isUnsupported($spec)) {
            throw new RuntimeException(
                Message::NATIVE_SPEC_UNSUPPORTED->getMessage($spec, $name),
            );
        }

        $package = $metadata[$name] ??= $this->registry->getMetadata($name);

        $range = NpmRange::parse($package->getDistTag($spec) ?? $spec, $name);

        $constraint = ['range' => $range, 'source' => $requirement->source, 'optional' => $requirement->optional];
        $current = $selected[$name] ?? null;

        if (null !== $current && $range->satisfies($current->version)) {
            $constraints[$name][] = $constraint;

            return null;
        }

        $candidates = [...$constraints[$name] ?? [], $constraint];

        if (null !== $current && $requirement->optional) {
            throw new RuntimeException(
                Message::NATIVE_VERSION_CONFLICT->getMessage($name, self::describe($candidates)),
            );
        }

        $required = $candidates;

        $version = self::highest($package, $candidates);

        if (null === $version && !$requirement->optional) {
            $required = [];

            foreach ($candidates as $candidate) {
                if (!$candidate['optional']) {
                    $required[] = $candidate;
                }
            }

            $version = self::highest($package, $required);
        }

        if (null === $version || isset($abandoned["{$name}@{$version->version}"])) {
            throw new RuntimeException(
                Message::NATIVE_VERSION_CONFLICT->getMessage($name, self::describe($required)),
            );
        }

        $kept = [];

        foreach ($candidates as $candidate) {
            if ($candidate['range']->satisfies($version->version)) {
                $kept[] = $candidate;

                continue;
            }

            $this->io->writeError(
                sprintf(
                    self::OPTIONAL_SKIPPED,
                    $name,
                    $candidate['range'],
                    Message::NATIVE_VERSION_CONFLICT->getMessage($name, self::describe($candidates)),
                ),
            );
        }

        $constraints[$name] = $kept;

        return $version;
    }
}
