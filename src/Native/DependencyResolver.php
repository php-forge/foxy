<?php

declare(strict_types=1);

namespace Foxy\Native;

use Composer\IO\IOInterface;
use Composer\Semver\Semver;
use Foxy\Exception\{Message, RuntimeException};

use function array_keys;
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
 * dropped until something requires it again. A selection is never upgraded because its constraints loosened. An
 * abandoned version satisfying every current constraint may be selected again once; abandoning it a second time is
 * final, and selecting it again is reported as a version conflict, so resolution always ends. Local packages,
 * selected by root `file:` requirements, are fixed selections that are never fetched; an optional one is an optional
 * subtree like any other, also dropped when a mandatory requirement rejects its version.
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
    private const string FILE_PROTOCOL = 'file:';
    private const string OPTIONAL_SKIPPED = '<warning>The optional dependency "%s" (%s) was skipped: %s</warning>';

    /**
     * @param NpmRegistryInterface $registry Registry that provides the package metadata.
     * @param IOInterface $io Output that receives the deprecation and skipped-dependency warnings.
     */
    public function __construct(private NpmRegistryInterface $registry, private IOInterface $io) {}

    /**
     * Resolves the requirements and their transitive dependencies.
     *
     * @param list<Requirement> $requirements Root requirements; a root `file:` requirement selects the local package of
     * the same name.
     * @param array<string, LocalPackage> $locals Local (`file:`) packages keyed by name. A selected local package is
     * never fetched: later requirements are checked against its version, and an optional one is dropped, with its
     * subtree, when a mandatory requirement rejects its version.
     *
     * @throws RuntimeException if a mandatory requirement uses an unsupported protocol, an invalid range, has no
     * version satisfying its mandatory constraints, would return to a version abandoned twice, rejects the version of a
     * local package, or the registry fails.
     *
     * @return array<string, ResolvedPackage> Selected registry and local packages keyed and sorted by name.
     */
    public function resolve(array $requirements, array $locals = []): array
    {
        $abandoned = [];
        $readmitted = [];
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

            $blockers = [];

            try {
                $version = $this->select(
                    $requirement,
                    $metadata,
                    $constraints,
                    $selected,
                    $abandoned,
                    $locals,
                    $blockers,
                );
            } catch (RuntimeException $exception) {
                if ($requirement->optional) {
                    $this->skip($requirement, $exception);

                    continue;
                }

                $root = $requirement->optionalRoot;

                if (null !== $root) {
                    $keys = self::dropSubtrees($root, $optionalRoots, $constraints, $selected, $dropped);

                    if ([] === $keys || !isset($dropped[$requirement->source])) {
                        throw $exception;
                    }

                    foreach ($keys as $key) {
                        $this->skip($optionalRoots[$key], $exception);
                    }

                    continue;
                }

                $released = false;

                foreach ($blockers as $blocker) {
                    if (isset($optionalRoots[$blocker])) {
                        foreach (self::dropSubtrees($blocker, $optionalRoots, $constraints, $selected, $dropped) as $key) {
                            $this->skip($optionalRoots[$key], $exception);

                            $released = true;
                        }
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

            $key = self::key($version);

            $previous = $selected[$requirement->name] ?? null;
            $selected[$requirement->name] = $version;

            unset($dropped[$key]);

            if (isset($abandoned[$key])) {
                $readmitted[$key] = $version;

                unset($abandoned[$key]);
            }

            if ($version instanceof PackageVersion && null !== $version->deprecated) {
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
                $source = self::key($previous);
                $abandoned[$source] = isset($readmitted[$source]);

                self::retract($source, $constraints, $selected, $dropped);
            }
        }

        $resolved = [];

        foreach (self::reachable($requirements, $selected) as $name => $version) {
            $resolved[$name] = $version instanceof LocalPackage
                ? ResolvedPackage::fromLocal($version->name, $version->version, $version->path)
                : ResolvedPackage::fromRegistry($version);
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
     * Unselects an optional package with the packages only its subtree requires, and returns the subtrees dropped.
     *
     * Every other optional subtree whose mandatory requirement selected the package is dropped with it, recursively.
     * Nothing changes, and an empty list is returned, when one of those packages is no longer selected at its
     * subtree's version or a globally mandatory constraint (neither optional nor inside an optional subtree) requires
     * it.
     *
     * @param string $key Selection at the top of the subtree, as {@see key()} returns it.
     * @param array<string, Requirement> $optionalRoots Optional requirements that started each subtree, keyed by the
     * selection at its top.
     * @param array<string, list<Constraint>> $constraints Constraints keyed by package name.
     * @param array<string, LocalPackage|PackageVersion> $selected Current selections.
     * @param array<string, LocalPackage|PackageVersion> $dropped Selections no longer required, keyed as {@see key()}
     * returns them.
     *
     * @return list<string> Keys of the dropped subtrees, the given one first.
     */
    private static function dropSubtrees(
        string $key,
        array $optionalRoots,
        array &$constraints,
        array &$selected,
        array &$dropped,
    ): array {
        $pending = [$key];
        $subtrees = [];

        while ([] !== $pending) {
            $current = array_shift($pending);

            if (isset($subtrees[$current])) {
                continue;
            }

            $name = $optionalRoots[$current]->name;
            $selection = $selected[$name] ?? null;

            if (null === $selection || self::key($selection) !== $current) {
                return [];
            }

            foreach ($constraints[$name] ?? [] as $constraint) {
                if ($constraint['optional']) {
                    continue;
                }

                if (null === $constraint['root']) {
                    return [];
                }

                $pending[] = $constraint['root'];
            }

            $subtrees[$current] = $name;
        }

        foreach ($subtrees as $subtree => $name) {
            $dropped[$subtree] = $selected[$name];
            $constraints[$name] = [];

            unset($selected[$name]);
        }

        foreach (array_keys($subtrees) as $subtree) {
            self::retract($subtree, $constraints, $selected, $dropped);
        }

        return array_keys($subtrees);
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
     * Returns the source string a selection gives its requirements: `name@version` for a registry version, the name
     * for a local package.
     */
    private static function key(LocalPackage|PackageVersion $selection): string
    {
        return $selection instanceof LocalPackage ? $selection->name : "{$selection->name}@{$selection->version}";
    }

    /**
     * Returns the selections reachable from the requirements through the selected versions' dependencies.
     *
     * @param list<Requirement> $requirements
     * @param array<string, LocalPackage|PackageVersion> $selected
     *
     * @return array<string, LocalPackage|PackageVersion>
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
     * Returns the requirements a selection declares: dependencies, non-optional peers, and optional dependencies.
     *
     * An optional dependency overrides a dependency or a peer of the same name, as npm does; a local package carries
     * its requirements already built.
     *
     * @param string|null $optionalRoot Optional subtree the requirements belong to, or `null` for none.
     *
     * @return list<Requirement>
     */
    private static function requirementsOf(
        LocalPackage|PackageVersion $version,
        string|null $optionalRoot = null,
    ): array {
        if ($version instanceof LocalPackage) {
            return array_map(
                static fn(Requirement $requirement): Requirement => new Requirement(
                    $requirement->name,
                    $requirement->spec,
                    $requirement->source,
                    $requirement->optional,
                    $optionalRoot,
                ),
                $version->requirements,
            );
        }

        $source = self::key($version);

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
     * @param string $source Replaced selection, as {@see key()} returns it, whose constraints are retracted.
     * @param array<string, list<Constraint>> $constraints Constraints keyed by package name.
     * @param array<string, LocalPackage|PackageVersion> $selected Current selections.
     * @param array<string, LocalPackage|PackageVersion> $dropped Selections no longer required, keyed as
     * {@see key()} returns them.
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
                    $key = self::key($selected[$name]);
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
     * mandatory requirement, the highest version satisfying the requirement and the globally mandatory constraints
     * (neither optional nor declared inside an optional subtree) is chosen: each optional constraint it violates is
     * dropped with a warning, and a violated constraint from an optional subtree makes the conflict name that subtree in
     * `$blockers` instead.
     *
     * @param array<string, PackageMetadata> $metadata Metadata fetched so far, keyed by name.
     * @param array<string, list<Constraint>> $constraints Constraints recorded so far, keyed by name.
     * @param array<string, LocalPackage|PackageVersion> $selected Current selections.
     * @param array<string, bool> $abandoned Selections replaced by a re-pick, keyed by `name@version`, with `true`
     * when the abandonment is final because the version was already selected again once.
     * @param array<string, LocalPackage> $locals Local packages keyed by name.
     * @param list<string> $blockers Receives, before a conflict is thrown, the optional subtrees whose dropping would
     * let a globally mandatory requirement through.
     *
     * @throws RuntimeException if the specification is unsupported or invalid, the registry fails, an optional
     * requirement rejects the current selection, no version satisfies the constraints, the version found was
     * abandoned for good, or the range rejects the version of a local package.
     */
    private function select(
        Requirement $requirement,
        array &$metadata,
        array &$constraints,
        array $selected,
        array $abandoned,
        array $locals,
        array &$blockers,
    ): LocalPackage|PackageVersion|null {
        $name = $requirement->name;
        $spec = $requirement->spec;
        $local = $locals[$name] ?? null;

        if (null !== $local && '' === $requirement->source && str_starts_with($spec, self::FILE_PROTOCOL)) {
            $constraints[$name][] = [
                'range' => NpmRange::parse('*', $name),
                'source' => '',
                'optional' => $requirement->optional,
                'root' => null,
            ];

            return $local;
        }

        if (self::isUnsupported($spec)) {
            throw new RuntimeException(
                Message::NATIVE_SPEC_UNSUPPORTED->getMessage($spec, $name),
            );
        }

        $current = $selected[$name] ?? null;

        if ($current instanceof LocalPackage) {
            $range = NpmRange::parse($spec, $name);
            $constraint = [
                'range' => $range,
                'source' => $requirement->source,
                'optional' => $requirement->optional,
                'root' => $requirement->optionalRoot,
            ];

            if ($range->satisfies($current->version)) {
                $constraints[$name][] = $constraint;

                return null;
            }

            $blockers[] = $current->name;

            throw new RuntimeException(
                Message::NATIVE_LOCAL_VERSION_CONFLICT->getMessage($name, $current->version, self::describe([$constraint])),
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

            foreach ($constraints[$name] ?? [] as $candidate) {
                if (!$candidate['optional'] && null === $candidate['root']) {
                    $required[] = $candidate;
                }
            }

            $required[] = $constraint;
            $version = self::highest($package, $required);
        }

        if (null === $version || ($abandoned["{$name}@{$version->version}"] ?? false)) {
            throw new RuntimeException(
                Message::NATIVE_VERSION_CONFLICT->getMessage($name, self::describe($required)),
            );
        }

        foreach ($candidates as $candidate) {
            if (!$candidate['optional'] && !$candidate['range']->satisfies($version->version)) {
                /** @var string $root Every globally mandatory constraint is satisfied, so this one is conditional. */
                $root = $candidate['root'];
                $blockers[] = $root;
            }
        }

        if ([] !== $blockers) {
            throw new RuntimeException(
                Message::NATIVE_VERSION_CONFLICT->getMessage($name, self::describe($candidates)),
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
}
