<?php

declare(strict_types=1);

namespace Foxy\Native;

/**
 * Represents one dependency requirement fed to the native resolver: a package name, its npm specification, and the
 * manifest or package that declared it.
 */
final readonly class Requirement
{
    /**
     * @param string $name Package name, scoped names included (`@scope/name`).
     * @param string $spec npm specification as written by the declaring manifest (range, version, or dist-tag).
     * @param string $source Declaring origin: `''` for the root manifest, the local package name, or `name@version`.
     * @param bool $optional Whether an unsatisfiable requirement is skipped with a warning instead of failing.
     * @param string|null $optionalRoot Selection (`name@version`) of the optional package whose subtree declared the
     * requirement, or `null` outside any optional subtree.
     */
    public function __construct(
        public string $name,
        public string $spec,
        public string $source,
        public bool $optional = false,
        public string|null $optionalRoot = null,
    ) {}
}
