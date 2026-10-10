<?php

declare(strict_types=1);

namespace Foxy\Native;

use Composer\IO\IOInterface;
use Composer\Semver\Semver;
use Foxy\Exception\{Message, RuntimeException};

use function array_map;
use function array_pop;
use function array_shift;
use function array_unshift;
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
 * re-selecting an abandoned version is reported as a version conflict, so resolution always ends. Local packages are
 * fixed selections that are never fetched.
 *
 * Dependencies, non-optional peer dependencies, and optional dependencies are followed. An optional constraint never
 * blocks a mandatory one: it is dropped with a warning instead, and an optional dependency that is unsatisfiable or
 * rejects the current selection is skipped with a warning. The packages selected through an optional dependency form
 * its subtree: a failure inside the subtree, or a mandatory requirement blocked by a constraint from it, drops the
 * whole subtree with a warning unless the optional package is also required by a mandatory constraint. Packages no
 * longer reachable from the requirements are dropped from the result.
 *
 * @phpstan-type Constraint array{range: NpmRange, source: string, optional: bool, root: string|null}
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
     * @param array<string, string> $locals Versions of the local (`file:`) packages keyed by name; a requirement on a
     * local package is checked against its version and never fetched, and local packages are not part of the result.
     *
     * @throws RuntimeException if a mandatory requirement uses an unsupported protocol, an invalid range, has no
     * version satisfying its mandatory constraints, would return to an abandoned version, rejects the version of a
     * local package, or the registry fails.
     *
     * @return array<string, ResolvedPackage> Selected packages keyed and sorted by name.
     */
    public function resolve(array $requirements, array $locals = []): array
    {
        $abandoned = [];
        $dropped = [];
        $metadata = [];
        $constraints = [];
        $optionalRoots = [];
        $selected = [];
        $queue = $requirements;

        while ([] !== $queue) {
            $requirement = array_shift($queue);

            if (isset($abandoned[$requirement->source]) || isset($dropped[$requirement->source])) {
                continue;
            }

            try {
                $version = $this->select($requirement, $metadata, $constraints, $selected, $abandoned, $locals);
            } catch (RuntimeException $exception) {
                if ($requirement->optional) {
                    $this->skip($requirement, $exception);

                    continue;
                }

                $root = $requirement->optionalRoot;

                if (null !== $root) {
                    if (
                        !self::dropSubtree($optionalRoots[$root], $root, $constraints, $selected, $dropped)
                        || !isset($dropped[$requirement->source])
                    ) {
                        throw $exception;
                    }

                    $this->skip($optionalRoots[$root], $exception);

                    continue;
                }

                $released = false;

                foreach (self::subtreeRoots($constraints[$requirement->name] ?? []) as $key) {
                    if (self::dropSubtree($optionalRoots[$key], $key, $constraints, $selected, $dropped)) {
                        $this->skip($optionalRoots[$key], $exception);

                        $released = true;
                    }
                }

                if (!$released) {
                    throw $exception;
                }

                array_unshift($queue, $requirement);

                continue;
            }

            if (null === $version) {
                continue;
            }

            $key = "{$version->name}@{$version->version}";
            $previous = $selected[$requirement->name] ?? null;
            $selected[$requirement->name] = $version;

            unset($dropped[$key]);

            if (null !== $version->deprecated) {
                $this->io->writeError(
                    sprintf(self::DEPRECATED, $version->name, $version->version, $version->deprecated),
                );
            }

            $root = $requirement->optionalRoot;

            if (null === $root && $requirement->optional) {
                $root = $key;
                $optionalRoots[$key] = $requirement;
            }

            foreach (self::requirementsOf($version, $root) as $dependency) {
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
     * @param list<array{range: NpmRange, source: string}> $constraints
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
     * Unselects an optional package and the packages only its subtree requires, and returns whether it did so.
     *
     * Nothing changes, and `false` is returned, when the package is no longer selected at the subtree's version or a
     * mandatory constraint requires it.
     *
     * @param Requirement $root Optional requirement that selected the package.
     * @param string $key Selection (`name@version`) at the top of the subtree.
     * @param array<string, list<Constraint>> $constraints Constraints keyed by package name.
     * @param array<string, PackageVersion> $selected Current selections.
     * @param array<string, PackageVersion> $dropped Selections no longer required, keyed by `name@version`.
     */
    private static function dropSubtree(
        Requirement $root,
        string $key,
        array &$constraints,
        array &$selected,
        array &$dropped,
    ): bool {
        $current = $selected[$root->name] ?? null;

        if (null === $current || "{$root->name}@{$current->version}" !== $key) {
            return false;
        }

        foreach ($constraints[$root->name] as $constraint) {
            if (!$constraint['optional']) {
                return false;
            }
        }

        $dropped[$key] = $current;
        $constraints[$root->name] = [];

        unset($selected[$root->name]);

        self::retract($key, $constraints, $selected, $dropped);

        return true;
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
     * @param string|null $optionalRoot Optional subtree the requirements belong to, or `null` for none.
     *
     * @return list<Requirement>
     */
    private static function requirementsOf(PackageVersion $version, string|null $optionalRoot = null): array
    {
        $source = "{$version->name}@{$version->version}";

        $optionalPeers = array_map(strval(...), $version->optionalPeerDependencies);

        $requirements = [];

        foreach ($version->dependencies as $name => $spec) {
            if (!isset($version->optionalDependencies[$name])) {
                $requirements[] = new Requirement((string) $name, $spec, $source, false, $optionalRoot);
            }
        }

        foreach ($version->peerDependencies as $name => $spec) {
            if (!isset($version->optionalDependencies[$name]) && !in_array((string) $name, $optionalPeers, true)) {
                $requirements[] = new Requirement((string) $name, $spec, $source, false, $optionalRoot);
            }
        }

        foreach ($version->optionalDependencies as $name => $spec) {
            $requirements[] = new Requirement((string) $name, $spec, $source, true, $optionalRoot);
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
     * @param array<string, string> $locals Versions of the local packages keyed by name.
     *
     * @throws RuntimeException if the specification is unsupported or invalid, the registry fails, an optional
     * requirement rejects the current selection, no version satisfies the constraints, the version found was
     * abandoned before, or the range rejects the version of a local package.
     */
    private function select(
        Requirement $requirement,
        array &$metadata,
        array &$constraints,
        array $selected,
        array $abandoned,
        array $locals,
    ): PackageVersion|null {
        $name = $requirement->name;
        $spec = $requirement->spec;

        if (self::isUnsupported($spec)) {
            throw new RuntimeException(
                Message::NATIVE_SPEC_UNSUPPORTED->getMessage($spec, $name),
            );
        }

        if (isset($locals[$name])) {
            $range = NpmRange::parse($spec, $name);

            if ($range->satisfies($locals[$name])) {
                return null;
            }

            throw new RuntimeException(
                Message::NATIVE_LOCAL_VERSION_CONFLICT->getMessage(
                    $name,
                    $locals[$name],
                    self::describe([['range' => $range, 'source' => $requirement->source]]),
                ),
            );
        }

        $package = $metadata[$name] ??= $this->registry->getMetadata($name);

        $range = NpmRange::parse($package->getDistTag($spec) ?? $spec, $name);

        $constraint = [
            'range' => $range,
            'source' => $requirement->source,
            'optional' => $requirement->optional,
            'root' => $requirement->optionalRoot,
        ];
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

    /**
     * Writes the warning of an optional dependency skipped because of the failure.
     */
    private function skip(Requirement $requirement, RuntimeException $failure): void
    {
        $this->io->writeError(
            sprintf(self::OPTIONAL_SKIPPED, $requirement->name, $requirement->spec, $failure->getMessage()),
        );
    }

    /**
     * Returns the optional subtrees (`name@version` of their top package) that declared any of the constraints.
     *
     * @param list<Constraint> $constraints
     *
     * @return array<string, string>
     */
    private static function subtreeRoots(array $constraints): array
    {
        $roots = [];

        foreach ($constraints as $constraint) {
            if (null !== $constraint['root']) {
                $roots[$constraint['root']] = $constraint['root'];
            }
        }

        return $roots;
    }
}
