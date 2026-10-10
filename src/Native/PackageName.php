<?php

declare(strict_types=1);

namespace Foxy\Native;

use function preg_match;
use function strlen;

/**
 * Validates the package names the native manager reads from manifests, lock files, and registry metadata.
 *
 * A valid name is at most 214 bytes long and is an optional `@scope/` prefix followed by a name, where the scope and
 * the name each start with an ASCII letter or digit and continue with letters, digits, `.`, `_`, or `-`.
 */
final readonly class PackageName
{
    private const int MAX_LENGTH = 214;
    private const string PATTERN = '/^(?:@[A-Za-z0-9][A-Za-z0-9._-]*\/)?[A-Za-z0-9][A-Za-z0-9._-]*$/D';

    /**
     * Returns whether the name matches the grammar, which rules out empty parts, path separators outside the scope
     * separator, leading dots, and absolute paths.
     */
    public static function isValid(string $name): bool
    {
        return strlen($name) <= self::MAX_LENGTH && 1 === preg_match(self::PATTERN, $name);
    }
}
