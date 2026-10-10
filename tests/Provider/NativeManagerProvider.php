<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

/**
 * Data provider for {@see \Foxy\Tests\Asset\NativeManagerTest} test cases.
 */
final class NativeManagerProvider
{
    /**
     * @return iterable<string, array{int|string|null, string|null, string}>
     */
    public static function installDirectories(): iterable
    {
        yield 'absolute path' => ['/opt/foxy/assets', null, '/opt/foxy/assets'];
        yield 'backslash separators' => ['vendor\\npm-asset\\', null, '{cwd}/vendor/npm-asset'];
        yield 'blank value' => ['   ', null, '{cwd}/node_modules'];
        yield 'non-string value' => [5, null, '{cwd}/node_modules'];
        yield 'relative path' => ['vendor/npm-asset', null, '{cwd}/vendor/npm-asset'];
        yield 'relative path under root package dir' => ['vendor/npm-asset', 'web', '{cwd}/web/vendor/npm-asset'];
        yield 'trailing slash' => ['vendor/npm-asset/', null, '{cwd}/vendor/npm-asset'];
        yield 'unset value' => [null, null, '{cwd}/node_modules'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidInstallDirectories(): iterable
    {
        yield 'current directory' => ['.', '{cwd}'];
        yield 'drive letter without separator' => ['C:', 'C:'];
        yield 'drive root' => ['C:/', 'C:/'];
        yield 'drive root with backslash' => ['C:\\', 'C:/'];
        yield 'filesystem root' => ['/', '/'];
        yield 'parent directory' => ['..', '{parent}'];
        yield 'root package directory' => ['{cwd}', '{cwd}'];
    }
}
