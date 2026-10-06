<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\Json\JsonFile;
use Composer\Package\RootPackageInterface;
use Composer\Util\{Filesystem, Platform, ProcessExecutor};
use Foxy\Asset\{AssetPackageInterface, DenoManager};
use Foxy\Config\Config;
use Foxy\Exception\RuntimeException;
use Foxy\Tests\Provider\DenoAssetManagerProvider;
use PHPUnit\Framework\Attributes\{DataProviderExternal, PreserveGlobalState, RunInSeparateProcess, TestWith};

use function array_key_exists;
use function array_map;
use function array_values;
use function define;
use function defined;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function putenv;
use function sprintf;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Unit tests for {@see DenoManager} commands, version detection, audit scope, and `workspaces` synchronization.
 *
 * {@see DenoAssetManagerProvider} for test case data providers.
 */
final class DenoAssetManagerTest extends AuditableAssetManager
{
    #[DataProviderExternal(DenoAssetManagerProvider::class, 'insideRootPackageDirectory')]
    public function testAddDependenciesAcceptsWorkspaceMemberInsideRootPackageDirectory(
        string $dependency,
        string $member,
    ): void {
        $assetPackage = $this->addDependenciesFromPackage(
            ['name' => 'app'],
            ['@composer-asset/foo--bar' => $dependency],
        );

        self::assertSame(
            [$member],
            $assetPackage->getPackage()['workspaces'],
            'The workspace members should match the expected value',
        );
    }

    public function testAddDependenciesAppendsWorkspaceMembersAfterUserEntries(): void
    {
        $assetPackage = $this->addDependenciesFromPackage(
            [
                'dependencies' => ['@composer-asset/foo--bar' => 'file:./path/foo/bar'],
                'workspaces' => ['path/foo/bar', 'packages/app', 'packages/*'],
            ],
            [
                '@composer-asset/foo--bar' => 'path/foo/bar/package.json',
                '@composer-asset/new--dependency' => 'path/new/dependency/package.json',
            ],
        );

        self::assertSame(
            ['packages/app', 'packages/*', 'path/foo/bar', 'path/new/dependency'],
            $assetPackage->getPackage()['workspaces'],
            'The workspace members should match the expected value',
        );
    }

    public function testAddDependenciesIgnoresPreviousDependenciesWithoutFileProtocol(): void
    {
        $assetPackage = $this->addDependenciesFromPackage(
            [
                'dependencies' => [
                    '@composer-asset/legacy--range' => '^1.0',
                    '@composer-asset/legacy--number' => 1,
                ],
                'workspaces' => ['packages' => ['packages/*']],
            ],
            [],
        );

        self::assertSame(
            ['dependencies' => [], 'workspaces' => ['packages' => ['packages/*']]],
            $assetPackage->getPackage(),
            'The dependencies and workspaces should match the expected value',
        );
    }

    public function testAddDependenciesIsIdempotent(): void
    {
        $dependencies = [
            '@composer-asset/foo--bar' => 'path/foo/bar/package.json',
            '@composer-asset/new--dependency' => 'path/new/dependency/package.json',
        ];

        $first = $this->addDependenciesFromPackage(['workspaces' => ['packages/*']], $dependencies);

        $firstContent = file_get_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json');

        $second = $this->getManager()->addDependencies($this->createRootPackage(), $dependencies);

        self::assertSame(
            $first->getPackage(),
            $second->getPackage(),
            'The packages should match the expected value',
        );
        self::assertSame(
            $firstContent,
            file_get_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json'),
            'The package.json content should match the expected value',
        );
        self::assertSame(
            ['packages/*', 'path/foo/bar', 'path/new/dependency'],
            $second->getPackage()['workspaces'],
            'The workspace members should match the expected value',
        );
    }

    #[DataProviderExternal(DenoAssetManagerProvider::class, 'unmanagedWorkspaces')]
    public function testAddDependenciesLeavesWorkspacesUntouchedWithoutComposerAssets(array $workspaces): void
    {
        $package = ['name' => 'app', 'workspaces' => $workspaces];

        self::assertSame(
            $package,
            $this->addDependenciesFromPackage($package, [])->getPackage(),
            'The package should remain unchanged when no composer assets are added',
        );
    }

    public function testAddDependenciesRemovesCanonicalMemberOfNonCanonicalStaleDependency(): void
    {
        $assetPackage = $this->addDependenciesFromPackage(
            [
                'dependencies' => ['@composer-asset/foo--bar' => 'file:./path/foo/bar/'],
                'workspaces' => ['packages/*', 'path/foo/bar'],
            ],
            [],
        );

        self::assertSame(
            ['packages/*'],
            $assetPackage->getPackage()['workspaces'],
            'The workspace members should match the expected value',
        );
    }

    public function testAddDependenciesRemovesEmptiedWorkspaces(): void
    {
        $assetPackage = $this->addDependenciesFromPackage(
            [
                'dependencies' => ['@composer-asset/foo--bar' => 'file:./path/foo/bar'],
                'workspaces' => ['path/foo/bar'],
            ],
            [],
        );

        self::assertSame(
            ['dependencies' => []],
            $assetPackage->getPackage(),
            'The dependencies and workspaces should match the expected value',
        );
    }

    public function testAddDependenciesRemovesStaleWorkspaceMember(): void
    {
        $assetPackage = $this->addDependenciesFromPackage(
            [
                'dependencies' => [
                    '@composer-asset/baz--bar' => 'file:./path/baz/bar',
                    '@composer-asset/foo--bar' => 'file:./path/foo/bar',
                ],
                'workspaces' => ['path/baz/bar', 'packages/*', 'path/foo/bar'],
            ],
            ['@composer-asset/foo--bar' => 'path/foo/bar/package.json'],
        );

        self::assertSame(
            ['packages/*', 'path/foo/bar'],
            $assetPackage->getPackage()['workspaces'],
            'The workspace members should match the expected value',
        );
    }

    public function testAddDependenciesRestoresFallbackWhenWorkspacesAreInvalid(): void
    {
        $this->fallback->expects(self::once())->method('restore');

        try {
            $this->addDependenciesFromPackage(
                ['workspaces' => 'packages/*'],
                ['@composer-asset/foo--bar' => 'path/foo/bar/package.json'],
            );
            self::fail(
                'Expected invalid workspaces to be rejected.',
            );
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith(
                'The "workspaces" field of ',
                $exception->getMessage(),
                'The exception message should indicate the invalid workspaces',
            );
        }
    }

    public function testAddDependenciesUsesRootPackageJsonDir(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'root-package';

        $this->sfs->mkdir($rootPackageDir);

        $this->config = new Config([], ['root-package-json-dir' => $rootPackageDir]);

        $this->manager = $this->getManager();

        $rootPackagePath = $rootPackageDir . DIRECTORY_SEPARATOR . $this->manager->getPackageName();
        $cwdPackagePath = $this->cwd . DIRECTORY_SEPARATOR . $this->manager->getPackageName();

        $rootPackageContent = "{\n    \"dependencies\": {\n        \"@composer-asset/foo--bar\": \"file:./assets/foo/bar\"\n    }\n}\n";
        $cwdPackageContent = "{\n    \"name\": \"cwd-package\"\n}\n";

        file_put_contents($rootPackagePath, $rootPackageContent);
        file_put_contents($cwdPackagePath, $cwdPackageContent);

        $dependencies = [
            '@composer-asset/foo--bar' => 'root-package/assets/foo/bar/package.json',
            '@composer-asset/new--dependency' => 'root-package/assets/new/dependency/package.json',
        ];

        $this->manager->addDependencies($this->createRootPackage(), $dependencies);

        self::assertSame(
            $cwdPackageContent,
            file_get_contents($cwdPackagePath),
            'The current working directory package JSON should remain unchanged',
        );
        self::assertSame(
            [
                'dependencies' => [
                    '@composer-asset/foo--bar' => 'file:./assets/foo/bar',
                    '@composer-asset/new--dependency' => 'file:./assets/new/dependency',
                ],
                'workspaces' => ['assets/foo/bar', 'assets/new/dependency'],
            ],
            (new JsonFile($rootPackagePath))->read(),
            'The root package JSON should include the added dependencies and workspaces',
        );
    }

    public function testAddDependenciesWritesWorkspacesToPackageJson(): void
    {
        $this->addDependenciesFromPackage(
            ['name' => 'app'],
            ['@composer-asset/foo--bar' => 'path/foo/bar/package.json'],
        );

        self::assertSame(
            "{\n    \"name\": \"app\",\n    \"dependencies\": {\n"
            . "        \"@composer-asset/foo--bar\": \"file:./path/foo/bar\"\n    },\n"
            . "    \"workspaces\": [\n        \"path/foo/bar\"\n    ]\n}\n",
            file_get_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json'),
            'The package JSON should include the added dependency and workspace',
        );
    }

    #[TestWith([false], 'all dependencies')]
    public function testAuditBuildsExactCommandWithoutInstallOptions(bool $noDev): void
    {
        parent::testAuditBuildsExactCommandWithoutInstallOptions($noDev);
    }

    public function testAuditSetsNoColorOnlyWhileTheProcessRuns(): void
    {
        $process = getenv('NO_COLOR');
        $envExists = array_key_exists('NO_COLOR', $_ENV);

        $env = $_ENV['NO_COLOR'] ?? null;

        $serverExists = array_key_exists('NO_COLOR', $_SERVER);

        $server = $_SERVER['NO_COLOR'] ?? null;

        try {
            putenv('NO_COLOR');
            unset($_ENV['NO_COLOR'], $_SERVER['NO_COLOR']);
            file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'deno.lock', '{}');

            $observed = [];
            $position = 0;

            $executor = $this->createMock(ProcessExecutor::class);

            $executor
                ->expects(self::exactly(2))
                ->method('execute')
                ->willReturnCallback(
                    static function (mixed $command, mixed &$output = null) use (&$observed, &$position): int {
                        if (0 === $position++) {
                            $output = '2.9.7';

                            return 0;
                        }

                        $observed = [getenv('NO_COLOR'), $_ENV['NO_COLOR'] ?? null, $_SERVER['NO_COLOR'] ?? null];
                        $output = 'No known vulnerabilities found';

                        return 0;
                    },
                );
            $executor
                ->expects(self::once())
                ->method('getErrorOutput')
                ->willReturn('');

            (new DenoManager($this->io, $this->config, $executor, new Filesystem(), $this->fallback))->audit(false);

            self::assertSame(
                ['1', '1', '1'],
                $observed,
                'The audit process should run with `NO_COLOR` set in every environment source',
            );
            self::assertFalse(
                getenv('NO_COLOR'),
                'The process environment should no longer define `NO_COLOR` after the audit',
            );
            self::assertArrayNotHasKey(
                'NO_COLOR',
                $_ENV,
                'The `$_ENV` superglobal should no longer define `NO_COLOR` after the audit',
            );
            self::assertArrayNotHasKey(
                'NO_COLOR',
                $_SERVER,
                'The `$_SERVER` superglobal should no longer define `NO_COLOR` after the audit',
            );
        } finally {
            putenv(false === $process ? 'NO_COLOR' : 'NO_COLOR=' . $process);

            if ($envExists) {
                $_ENV['NO_COLOR'] = $env;
            } else {
                unset($_ENV['NO_COLOR']);
            }

            if ($serverExists) {
                $_SERVER['NO_COLOR'] = $server;
            } else {
                unset($_SERVER['NO_COLOR']);
            }
        }
    }

    public function testIsInstalledRequiresLockFile(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', '{}');

        $this->sfs->mkdir($this->cwd . DIRECTORY_SEPARATOR . 'node_modules');

        self::assertFalse(
            $this->manager->isInstalled(),
            'The manager should report not installed when the lock file is missing',
        );

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'deno.lock', '{}');

        self::assertTrue(
            $this->manager->isInstalled(),
            'The manager should report installed when the lock file is present',
        );
    }

    public function testRunAppliesConfiguredOptionsToEachUpdateStep(): void
    {
        $this->config = new Config(
            [
                'run-asset-manager' => true,
                'manager-options' => '--quiet',
                'manager-install-options' => '--allow-scripts',
                'manager-update-options' => '--latest',
            ],
        );

        $this->manager = $this->getManager();

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', '{}');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'deno.lock', '{}');

        $this->sfs->mkdir($this->cwd . DIRECTORY_SEPARATOR . 'node_modules');

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        self::assertSame(
            0,
            $this->manager->run(),
            'The manager should return 0 after running successfully',
        );
        self::assertSame(
            sprintf(
                '%1$s update --lockfile-only --recursive --quiet --latest && %1$s install --quiet --allow-scripts',
                $this->getBinary(),
            ),
            $this->executor->getLastCommand(),
        );
    }

    public function testRunAppliesConfiguredOptionsToInstallCommand(): void
    {
        $this->config = new Config(
            [
                'run-asset-manager' => true,
                'manager-options' => '--quiet',
                'manager-install-options' => '--allow-scripts',
                'manager-update-options' => '--latest',
            ],
        );

        $this->manager = $this->getManager();

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        self::assertSame(
            0,
            $this->manager->run(),
            'The manager should return 0 after running successfully',
        );
        self::assertSame(
            $this->getBinary() . ' install --quiet --allow-scripts',
            $this->executor->getLastCommand(),
            'The install command should include the configured options',
        );
    }

    #[DataProviderExternal(DenoAssetManagerProvider::class, 'invalidWorkspaces')]
    public function testThrowRuntimeExceptionForInvalidWorkspaces(mixed $workspaces): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            sprintf(
                'The "workspaces" field of "%s" must be a list of strings to install Composer assets with deno.',
                $this->cwd . DIRECTORY_SEPARATOR . 'package.json',
            ),
        );

        $this->addDependenciesFromPackage(
            ['workspaces' => $workspaces],
            ['@composer-asset/foo--bar' => 'path/foo/bar/package.json'],
        );
    }

    #[DataProviderExternal(DenoAssetManagerProvider::class, 'nonNestedAssetPaths')]
    public function testThrowRuntimeExceptionForNonNestedAssetPath(string $dependency): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The Composer asset "@composer-asset/foo--bar" must be located in a subdirectory of the root package '
            . 'directory to be installed with deno.',
        );

        $this->addDependenciesFromPackage(['name' => 'app'], ['@composer-asset/foo--bar' => $dependency]);
    }

    public function testThrowRuntimeExceptionWhenNativeVersionOutputIsUnsupported(): void
    {
        $this->executor->addExpectedValues(
            0,
            "deno 2.9.6 (stable, release, x86_64-unknown-linux-gnu)\nv8 15.0.245.2-rusty\ntypescript 6.0.3\n",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The installed deno version "2.9.6" doesn\'t match with the supported version constraint "^2.9.7"',
        );

        $this->manager->validate();
    }

    public function testThrowRuntimeExceptionWhenOutsideAssetIsCombinedWithInvalidWorkspaces(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The Composer asset "@composer-asset/foo--bar" must be located in a subdirectory of the root package '
            . 'directory to be installed with deno.',
        );

        $this->addDependenciesFromPackage(
            ['workspaces' => ['packages' => ['packages/*']]],
            ['@composer-asset/foo--bar' => 'file:../vendor/foo/bar'],
        );
    }

    public function testThrowRuntimeExceptionWhenProductionOnlyAuditIsRequested(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'deno.lock', '{}');

        try {
            $this->getManager()->audit(true);

            self::fail(
                'Expected the production-only deno audit to be rejected.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                'The deno audit cannot guarantee the requested dependency scope because "deno audit" cannot exclude '
                . 'development dependencies.',
                $exception->getMessage(),
                'The exception message should explain why the dependency scope cannot be guaranteed',
            );
        }

        self::assertNull(
            $this->executor->getExecutedCommand(0),
            'The rejection should happen before any process is executed',
        );
    }

    public function testThrowRuntimeExceptionWhenVersionOutputDoesNotStartWithDeno(): void
    {
        $this->executor->addExpectedValues(0, 'upgraded deno 2.9.7');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The installed deno version "upgraded deno 2.9.7" doesn\'t match with the supported version constraint '
            . '"^2.9.7"',
        );

        $this->manager->validate();
    }

    public function testValidateAcceptsNativeVersionOutput(): void
    {
        $this->config = new Config([], ['manager-version' => '2.9.7']);

        $this->manager = $this->getManager();

        $this->executor->addExpectedValues(
            0,
            "deno 2.9.7 (stable, release, x86_64-unknown-linux-gnu)\nv8 15.0.245.2-rusty\ntypescript 6.0.3\n",
        );

        $this->manager->validate();

        self::assertSame(
            $this->getValidVersionCommand(),
            $this->executor->getExecutedCommand(0),
            'The version command should match the expected format',
        );
        self::assertNull(
            $this->executor->getExecutedCommand(1),
            'There should be no second executed command',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWindowsCommandsUseExecutableNameAndNormalizedCustomPath(): void
    {
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            define('PHP_WINDOWS_VERSION_BUILD', 1);
        }

        $this->executor->addExpectedValues(0, '2.9.7');

        $this->manager = $this->getManager();

        $this->manager->validate();

        self::assertSame(
            'deno.exe --version',
            $this->executor->getLastCommand(),
            'The version command for Windows should use the executable name and normalized path',
        );

        $this->config = new Config(['run-asset-manager' => true, 'manager-bin' => 'C:/tools/deno.exe']);

        $this->executor->addExpectedValues(0, '2.9.7');
        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        self::assertSame(
            0,
            $this->getManager()->run(),
            'The manager should return 0 after running successfully',
        );
        self::assertSame(
            'C:\\tools\\deno.exe install',
            $this->executor->getLastCommand(),
            'The install command should use the normalized custom path for Windows',
        );

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', '{}');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'deno.lock', '{}');

        $this->sfs->mkdir($this->cwd . DIRECTORY_SEPARATOR . 'node_modules');

        $this->executor->addExpectedValues(0, '2.9.7');
        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        self::assertSame(
            0,
            $this->getManager()->run(),
            'The manager should return 0 after running successfully',
        );
        self::assertSame(
            'C:\\tools\\deno.exe update --lockfile-only --recursive && C:\\tools\\deno.exe install',
            $this->executor->getLastCommand(),
            'The update command should use the normalized custom path for Windows',
        );

        $this->executor->addExpectedValues(0, '2.9.7');
        $this->executor->addExpectedValues(0, 'No known vulnerabilities found');

        $this->getManager()->audit(false);

        self::assertSame(
            'C:\\tools\\deno.exe audit --level=low',
            $this->executor->getLastCommand(),
            'The audit command should use the normalized custom path for Windows',
        );
    }

    protected function getExpectedPackage(array $package): array
    {
        $package['workspaces'] = array_map(
            static fn(string $dependency): string => substr($dependency, strlen('file:./')),
            array_values($package['dependencies']),
        );

        return $package;
    }

    protected function getManager(): DenoManager
    {
        return new DenoManager($this->io, $this->config, $this->executor, new Filesystem(), $this->fallback);
    }

    protected function getUnsupportedVersion(): string
    {
        return '2.9.6';
    }

    protected function getValidAuditCommand(bool $noDev): string
    {
        return $this->getBinary() . ' audit --level=low';
    }

    protected function getValidInstallCommand(): string
    {
        return $this->getBinary() . ' install';
    }

    protected function getValidLockPackageName(): string
    {
        return 'deno.lock';
    }

    protected function getValidName(): string
    {
        return 'deno';
    }

    protected function getValidUpdateCommand(): string
    {
        return $this->getBinary() . ' update --lockfile-only --recursive && ' . $this->getValidInstallCommand();
    }

    protected function getValidVersion(): string
    {
        return '2.9.7';
    }

    protected function getValidVersionCommand(): string
    {
        return $this->getBinary() . ' --version';
    }

    protected function getValidVersionConstraint(): string
    {
        return '^2.9.7';
    }

    /**
     * @param array $package The root package written to `package.json` before the merge.
     * @param array $dependencies The Composer asset dependencies to merge.
     */
    private function addDependenciesFromPackage(array $package, array $dependencies): AssetPackageInterface
    {
        (new JsonFile($this->cwd . DIRECTORY_SEPARATOR . 'package.json'))->write($package);

        return $this->getManager()->addDependencies($this->createRootPackage(), $dependencies);
    }

    private function createRootPackage(): RootPackageInterface
    {
        $rootPackage = $this->createMock(RootPackageInterface::class);

        $rootPackage->method('getLicense')->willReturn([]);

        return $rootPackage;
    }

    private function getBinary(): string
    {
        return Platform::isWindows() ? 'deno.exe' : 'deno';
    }
}
