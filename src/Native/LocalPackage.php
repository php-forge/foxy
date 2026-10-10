<?php

declare(strict_types=1);

namespace Foxy\Native;

/**
 * Represents a local `file:` package of the root manifest as the resolver selects it: a fixed version and the
 * requirements its own manifest declares.
 */
final readonly class LocalPackage
{
    /**
     * @param string $name Package name, the source of its requirements.
     * @param string $version Version declared by its `package.json` (`0.0.0` when absent).
     * @param string $path Local package directory: the manifest's `file:` value without the prefix, as written.
     * @param list<Requirement> $requirements Dependencies, non-optional peers, and optional dependencies it declares.
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $path,
        public array $requirements,
    ) {}
}
