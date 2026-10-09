<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Asset\NpmAssetManagerTest} test cases.
 */
final class NpmAssetManagerProvider
{
    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function workspaceLocksThatCannotBeEnumerated(): iterable
    {
        yield 'legacy lock without package map' => [
            '{"workspaces":["packages/*"]}',
            '{"lockfileVersion":1}',
        ];
        yield 'lock without workspace entries' => [
            '{"workspaces":["packages/*"]}',
            '{"packages":{"":{"workspaces":["packages/*"]}}}',
        ];
        yield 'stale workspace declaration' => [
            '{"workspaces":["packages/*"]}',
            '{"packages":{"":{"workspaces":["other/*"]},"packages/a":{}}}',
        ];
        yield 'stale secondary workspace declaration' => [
            '{"workspaces":["packages/*","apps/*"]}',
            '{"packages":{"":{"workspaces":["packages/*","services/*"]},"packages/a":{}}}',
        ];
        yield 'manifest without locked workspaces' => [
            '{}',
            '{"packages":{"":{"workspaces":["packages/*"]},"packages/a":{}}}',
        ];
        yield 'missing manifest with locked workspaces' => [
            null,
            '{"packages":{"":{"workspaces":["packages/*"]},"packages/a":{}}}',
        ];
        yield 'malformed manifest workspace declaration' => [
            '{"workspaces":"packages/*"}',
            '{"packages":{"":{}}}',
        ];
        yield 'manifest workspace declaration with a non-string pattern' => [
            '{"workspaces":[null]}',
            '{"packages":{"":{}}}',
        ];
        yield 'manifest workspace declaration with a blank pattern' => [
            '{"workspaces":[" "]}',
            '{"packages":{"":{"workspaces":[" "]},"packages/a":{}}}',
        ];
        yield 'malformed locked workspace declaration' => [
            '{}',
            '{"packages":{"":{"workspaces":{"packages":"packages/*"}}}}',
        ];
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function workspaceManifests(): iterable
    {
        yield 'workspace list' => [
            '{"workspaces":["packages/*"]}',
            '{"packages":{"":{"workspaces":["packages/*"]},"node_modules/a":{"link":true,"resolved":"packages/a"},"packages/a":{}}}',
            ['packages/a'],
        ];
        yield 'workspace packages object' => [
            '{"workspaces":{"packages":["packages/*"]}}',
            '{"packages":{"":{"workspaces":{"packages":["packages/*"]}},"node_modules/a":{"link":true,"resolved":"packages/a"},"packages/a":{}}}',
            ['packages/a'],
        ];
        yield 'dot-leading workspace path' => [
            '{"workspaces":["visible",".hidden"]}',
            '{"packages":{"":{"workspaces":["visible",".hidden"]},".hidden":{},"node_modules/hidden":{"link":true,"resolved":".hidden"},"node_modules/visible":{"link":true,"resolved":"visible"},"visible":{}}}',
            ['.hidden', 'visible'],
        ];
        yield 'numeric workspace path' => [
            '{"workspaces":["0"]}',
            '{"packages":{"":{"workspaces":["0"]},"0":{},"node_modules/zero":{"link":true,"resolved":"0"}}}',
            ['0'],
        ];
        yield 'node_modules path separators and boundary' => [
            '{"workspaces":["packages/*"]}',
            '{"packages":{"":{"workspaces":["packages/*"]},"node_modules":{},"packages/a":{},"packages\\\\a\\\\node_modules\\\\hidden":{}}}',
            ['packages/a'],
        ];
    }
}
