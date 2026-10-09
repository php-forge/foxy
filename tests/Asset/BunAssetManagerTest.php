<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\Util\Platform;
use Foxy\Asset\BunManager;
use Foxy\Config\Config;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Tests\Provider\BunAssetManagerProvider;
use PHPUnit\Framework\Attributes\{DataProviderExternal, PreserveGlobalState, RunInSeparateProcess};
use Xepozz\InternalMocker\MockerState;

use function array_key_exists;
use function define;
use function defined;
use function file_put_contents;
use function getenv;
use function putenv;

use const DIRECTORY_SEPARATOR;

/**
 * Unit tests for {@see BunManager} commands, version detection, and audit scope checks of `.npmrc` and `bunfig.toml`.
 *
 * {@see BunAssetManagerProvider} for test case data providers.
 */
final class BunAssetManagerTest extends AuditableAssetManager
{
    /**
     * @var array<string, array{
     *     process: string|false,
     *     envExists: bool,
     *     env: mixed,
     *     serverExists: bool,
     *     server: mixed,
     * }>
     */
    private array $environmentState = [];

    #[DataProviderExternal(BunAssetManagerProvider::class, 'benignAuditConfigurations')]
    public function testAuditAcceptsBenignConfiguration(string $file, string $contents, bool $noDev): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . $file, $contents);

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');
        $this->getManager()->audit($noDev);

        self::assertSame(
            $this->getValidAuditCommand($noDev),
            $this->executor->getExecutedCommand(1),
            'The audit command must match the requested dependency scope.',
        );
    }

    public function testAuditAcceptsBenignHomeAndXdgConfigurations(): void
    {
        $xdgConfigHome = $this->cwd . DIRECTORY_SEPARATOR . 'xdg-config';

        $this->sfs->mkdir($xdgConfigHome);

        putenv("XDG_CONFIG_HOME={$xdgConfigHome}" . DIRECTORY_SEPARATOR);

        $_ENV['XDG_CONFIG_HOME'] = $xdgConfigHome . DIRECTORY_SEPARATOR;
        $_SERVER['XDG_CONFIG_HOME'] = $xdgConfigHome . DIRECTORY_SEPARATOR;

        file_put_contents(
            $this->cwd . DIRECTORY_SEPARATOR . 'bun.lock',
            '{}',
        );
        file_put_contents(
            $this->cwd . DIRECTORY_SEPARATOR . '.npmrc',
            "\n # comment\n ; comment\nstrict-ssl\nregistry=https://registry.npmjs.org/\nomit=\n",
        );
        file_put_contents(
            $xdgConfigHome . DIRECTORY_SEPARATOR . '.bunfig.toml',
            "[install]\nnote = [{ value = \"safe\" }]\nproduction = false\ndev = true\n"
            . "[other]\ninstall.optional = true\n",
        );

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');
        $this->getManager()->audit(false);

        self::assertSame(
            $this->getValidAuditCommand(false),
            $this->executor->getExecutedCommand(1),
            'Benign home configuration must allow the audit command.',
        );
    }

    #[DataProviderExternal(BunAssetManagerProvider::class, 'restrictiveAuditConfigurations')]
    public function testAuditFailsClosedForRestrictiveConfiguration(
        string $file,
        string $contents,
        bool $noDev,
        string $setting,
    ): void {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . $file, $contents);

        $manager = $this->getManager();

        try {
            $manager->audit($noDev);

            self::fail(
                'Expected a restrictive Bun configuration to be rejected.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED->getMessage(
                    $this->cwd . DIRECTORY_SEPARATOR . $file,
                    $setting,
                ),
                $exception->getMessage(),
                'Message must name the file and the restrictive setting.',
            );
        }

        self::assertNull(
            $this->executor->getExecutedCommand(0),
            'Rejected configuration must stop before process execution.',
        );
    }

    #[DataProviderExternal(BunAssetManagerProvider::class, 'unverifiableAuditConfigurations')]
    public function testAuditFailsClosedForUnverifiableConfiguration(
        string $file,
        string $contents,
        string $reason,
    ): void {
        $path = $this->cwd . DIRECTORY_SEPARATOR . $file;

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        file_put_contents($path, $contents);

        try {
            $this->getManager()->audit(false);

            self::fail(
                'Expected an unverifiable Bun configuration to be rejected.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_BUN_AUDIT_SCOPE_UNVERIFIABLE->getMessage($path, $reason),
                $exception->getMessage(),
                'Message must name the file and the unverifiable construct.',
            );
        }

        self::assertNull(
            $this->executor->getExecutedCommand(0),
            'Unverifiable configuration must stop before process execution.',
        );
    }

    public function testAuditIgnoresAnEnvironmentVariableThatTheManagerProcessWillDrop(): void
    {
        $home = $this->cwd . DIRECTORY_SEPARATOR . 'home';
        $droppedXdgConfig = $this->cwd . DIRECTORY_SEPARATOR . 'dropped-xdg-config';

        $this->sfs->mkdir([$home, $droppedXdgConfig]);

        putenv("HOME={$home}");

        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;

        putenv("XDG_CONFIG_HOME={$droppedXdgConfig}");
        unset($_ENV['XDG_CONFIG_HOME'], $_SERVER['XDG_CONFIG_HOME']);
        file_put_contents($droppedXdgConfig . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(true);

        self::assertSame(
            $this->getValidAuditCommand(true),
            $this->executor->getExecutedCommand(1),
            'Dropped environment values must not restrict the audit command.',
        );
    }

    public function testAuditIgnoresAServerOnlyEnvironmentVariable(): void
    {
        $serverOnlyXdgConfig = $this->cwd . DIRECTORY_SEPARATOR . 'server-only-xdg-config';

        $this->sfs->mkdir($serverOnlyXdgConfig);

        putenv('XDG_CONFIG_HOME');
        unset($_ENV['XDG_CONFIG_HOME']);

        $_SERVER['XDG_CONFIG_HOME'] = $serverOnlyXdgConfig;

        file_put_contents($serverOnlyXdgConfig . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(true);

        self::assertSame(
            $this->getValidAuditCommand(true),
            $this->executor->getExecutedCommand(1),
            'Server-only environment values must not restrict the audit command.',
        );
    }

    public function testAuditIgnoresNonScalarEnvironmentValues(): void
    {
        $home = $this->cwd . DIRECTORY_SEPARATOR . 'home';

        $this->sfs->mkdir($home);

        putenv("HOME={$home}");

        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;
        $_ENV['XDG_CONFIG_HOME'] = [];

        file_put_contents($home . DIRECTORY_SEPARATOR . '.bunfig.toml', "[install]\noptional = false\n");
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED->getMessage(
                $home . DIRECTORY_SEPARATOR . '.bunfig.toml',
                'install.optional=false',
            ),
        );

        $this->getManager()->audit(true);
    }

    public function testAuditNoDevAllowsDevelopmentOnlyRestrictions(): void
    {
        file_put_contents(
            $this->cwd . DIRECTORY_SEPARATOR . 'bun.lock',
            '{}',
        );
        file_put_contents(
            $this->cwd . DIRECTORY_SEPARATOR . '.npmrc',
            'omit=dev',
        );
        file_put_contents(
            $this->cwd . DIRECTORY_SEPARATOR . 'bunfig.toml',
            "[install]\nproduction = true\ndev = false\noptional = true\npeer = true\n",
        );

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(true);

        self::assertSame(
            $this->getValidAuditCommand(true),
            $this->executor->getExecutedCommand(1),
            'No-dev audit must preserve the production-only command scope.',
        );
    }

    public function testAuditNormalizesConfigurationDirectorySeparators(): void
    {
        $xdgConfigHome = $this->cwd . DIRECTORY_SEPARATOR . 'xdg-config';

        $this->sfs->mkdir($xdgConfigHome);

        putenv("XDG_CONFIG_HOME={$xdgConfigHome}" . DIRECTORY_SEPARATOR);

        $_ENV['XDG_CONFIG_HOME'] = $xdgConfigHome . DIRECTORY_SEPARATOR;
        $_SERVER['XDG_CONFIG_HOME'] = $xdgConfigHome . DIRECTORY_SEPARATOR;

        file_put_contents($xdgConfigHome . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        try {
            $this->getManager()->audit(true);

            self::fail(
                'Expected the normalized XDG npmrc configuration to be rejected.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED->getMessage(
                    $xdgConfigHome . DIRECTORY_SEPARATOR . '.npmrc',
                    'omit=optional',
                ),
                $exception->getMessage(),
                'Path must use a single trailing separator.',
            );
        }

        self::assertNull(
            $this->executor->getExecutedCommand(0),
            'Rejected normalized configuration must stop before process execution.',
        );
    }

    public function testAuditPrefersHomeOverUserProfile(): void
    {
        $home = $this->cwd . DIRECTORY_SEPARATOR . 'home';
        $userProfile = $this->cwd . DIRECTORY_SEPARATOR . 'user-profile';

        $this->sfs->mkdir([$home, $userProfile]);

        putenv("HOME={$home}");
        putenv("USERPROFILE={$userProfile}");

        $_ENV['HOME'] = $home;
        $_ENV['USERPROFILE'] = $userProfile;
        $_SERVER['HOME'] = $home;
        $_SERVER['USERPROFILE'] = $userProfile;

        file_put_contents($home . DIRECTORY_SEPARATOR . '.bunfig.toml', "[install]\noptional = false\n");
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED->getMessage(
                $home . DIRECTORY_SEPARATOR . '.bunfig.toml',
                'install.optional=false',
            ),
        );

        $this->getManager()->audit(true);
    }

    public function testAuditPreservesQuotedTomlCharactersDuringPreflight(): void
    {
        file_put_contents(
            $this->cwd . DIRECTORY_SEPARATOR . 'bun.lock',
            '{}',
        );
        file_put_contents(
            $this->cwd . DIRECTORY_SEPARATOR . 'bunfig.toml',
            "[install]\nnote = [\"]\", '}#', \"escaped \\\"#\"] # comment\noptional = true\n",
        );

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(false);

        self::assertSame(
            $this->getValidAuditCommand(false),
            $this->executor->getExecutedCommand(1),
            'Quoted TOML values must not alter the audit command.',
        );
    }

    public function testAuditReadsHomeNpmrcWithoutXdgConfigHome(): void
    {
        $home = $this->cwd . DIRECTORY_SEPARATOR . 'home';

        $this->sfs->mkdir($home);

        putenv("HOME={$home}");

        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;
        $homeNpmrc = $home . DIRECTORY_SEPARATOR . '.npmrc';

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        file_put_contents($homeNpmrc, 'omit=dev');

        try {
            $this->getManager()->audit(false);

            self::fail(
                'Expected the HOME npmrc to be read when XDG_CONFIG_HOME is unset.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED->getMessage($homeNpmrc, 'omit=dev'),
                $exception->getMessage(),
                'HOME path must be the rejected file.',
            );
        }

        self::assertNull($this->executor->getExecutedCommand(0), 'Audit must not run.');
    }

    public function testAuditReadsXdgBunfigBeforeHomeBunfig(): void
    {
        $home = $this->cwd . DIRECTORY_SEPARATOR . 'home';
        $xdgConfigHome = $this->cwd . DIRECTORY_SEPARATOR . 'xdg-config';

        $this->sfs->mkdir([$home, $xdgConfigHome]);

        putenv("HOME={$home}");
        putenv("XDG_CONFIG_HOME={$xdgConfigHome}");

        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;
        $_ENV['XDG_CONFIG_HOME'] = $xdgConfigHome;
        $_SERVER['XDG_CONFIG_HOME'] = $xdgConfigHome;
        $xdgBunfig = $xdgConfigHome . DIRECTORY_SEPARATOR . '.bunfig.toml';

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        file_put_contents($home . DIRECTORY_SEPARATOR . '.bunfig.toml', "[install]\noptional = false\n");
        file_put_contents($xdgBunfig, "[install]\nproduction = true\n");

        try {
            $this->getManager()->audit(false);

            self::fail(
                'Expected the XDG bunfig to be read before the HOME bunfig.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED->getMessage($xdgBunfig, 'install.production=true'),
                $exception->getMessage(),
                'XDG path must be the rejected file.',
            );
        }

        self::assertNull(
            $this->executor->getExecutedCommand(0),
            'Audit must not run.',
        );
    }

    public function testAuditRejectsConfigurationDirectory(): void
    {
        $path = $this->cwd . DIRECTORY_SEPARATOR . '.npmrc';

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->sfs->mkdir($path);

        try {
            $this->getManager()->audit(false);

            self::fail(
                'Expected a Bun configuration directory to be rejected.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_BUN_AUDIT_CONFIG_UNREADABLE->getMessage($path),
                $exception->getMessage(),
                'Message must name the unreadable path.',
            );
        }

        self::assertNull(
            $this->executor->getExecutedCommand(0),
            'A rejected configuration must stop before process execution.',
        );
    }

    public function testAuditRejectsConfigurationReadFailure(): void
    {
        $path = $this->cwd . DIRECTORY_SEPARATOR . '.npmrc';

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        file_put_contents($path, 'registry=https://registry.npmjs.org/');

        MockerState::addCondition(
            'Foxy\\Asset',
            'file_get_contents',
            [$path, false, null, 0, null],
            false,
        );

        try {
            $this->getManager()->audit(false);

            self::fail(
                'Expected a Bun configuration read failure to be rejected.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_BUN_AUDIT_CONFIG_UNREADABLE->getMessage($path),
                $exception->getMessage(),
                'Message must name the unreadable path.',
            );
        }

        self::assertNull(
            $this->executor->getExecutedCommand(0),
            'Unreadable configuration must stop before process execution.',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAuditUsesProcessEnvironmentWhenServerIntersectionIsEmpty(): void
    {
        $xdgConfigHome = $this->cwd . DIRECTORY_SEPARATOR . 'xdg-config';

        $this->sfs->mkdir($xdgConfigHome);

        putenv("XDG_CONFIG_HOME={$xdgConfigHome}");

        $_ENV = [];
        $_SERVER = [];

        file_put_contents($xdgConfigHome . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED->getMessage(
                $xdgConfigHome . DIRECTORY_SEPARATOR . '.npmrc',
                'omit=optional',
            ),
        );

        $this->getManager()->audit(true);
    }

    public function testAuditUsesTheSameEnvironmentPrecedenceAsTheManagerProcess(): void
    {
        $environmentConfig = $this->cwd . DIRECTORY_SEPARATOR . 'environment-config';
        $processConfig = $this->cwd . DIRECTORY_SEPARATOR . 'process-config';

        $this->sfs->mkdir([$environmentConfig, $processConfig]);

        putenv("XDG_CONFIG_HOME={$processConfig}");

        $_ENV['XDG_CONFIG_HOME'] = $environmentConfig;
        $_SERVER['XDG_CONFIG_HOME'] = $processConfig;

        file_put_contents($environmentConfig . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED->getMessage(
                $environmentConfig . DIRECTORY_SEPARATOR . '.npmrc',
                'omit=optional',
            ),
        );

        $this->getManager()->audit(true);
    }

    public function testAuditValidatesBothXdgAndRootNpmrcConfigurations(): void
    {
        $xdgConfigHome = $this->cwd . DIRECTORY_SEPARATOR . 'xdg-config';

        $this->sfs->mkdir($xdgConfigHome);

        putenv("XDG_CONFIG_HOME={$xdgConfigHome}");

        $_ENV['XDG_CONFIG_HOME'] = $xdgConfigHome;
        $_SERVER['XDG_CONFIG_HOME'] = $xdgConfigHome;

        file_put_contents($xdgConfigHome . DIRECTORY_SEPARATOR . '.npmrc', 'registry=https://registry.npmjs.org/');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED->getMessage(
                $this->cwd . DIRECTORY_SEPARATOR . '.npmrc',
                'omit=optional',
            ),
        );

        $this->getManager()->audit(true);
    }

    public function testIgnoresLegacyBinaryLockFile(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lockb', 'legacy');

        self::assertFalse(
            $this->manager->hasLockFile(),
            'A legacy binary lock file must not count as a lock file.',
        );
    }

    public function testIgnoresLegacyBinaryLockFileInConfiguredRootDirectory(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'web';

        $this->sfs->mkdir($rootPackageDir);

        $this->config = new Config([], ['root-package-json-dir' => $rootPackageDir]);

        $this->manager = $this->getManager();

        file_put_contents($rootPackageDir . DIRECTORY_SEPARATOR . 'bun.lockb', 'legacy');

        self::assertFalse(
            $this->manager->hasLockFile(),
            'A legacy binary lock file must not count as a lock file.',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWindowsAuditDropsProcessOnlyEnvironmentVariable(): void
    {
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            define('PHP_WINDOWS_VERSION_BUILD', 1);
        }

        $droppedXdgConfig = $this->cwd . DIRECTORY_SEPARATOR . 'dropped-xdg-config';

        $this->sfs->mkdir($droppedXdgConfig);

        putenv("XDG_CONFIG_HOME={$droppedXdgConfig}");
        unset($_ENV['XDG_CONFIG_HOME'], $_SERVER['XDG_CONFIG_HOME']);
        file_put_contents($droppedXdgConfig . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(true);

        self::assertSame(
            $this->getValidAuditCommand(true),
            $this->executor->getExecutedCommand(1),
            'The Windows audit command must use process environment precedence.',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWindowsAuditIgnoresAServerOnlyEnvironmentVariable(): void
    {
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            define('PHP_WINDOWS_VERSION_BUILD', 1);
        }

        $serverOnlyXdgConfig = $this->cwd . DIRECTORY_SEPARATOR . 'server-only-xdg-config';

        $this->sfs->mkdir($serverOnlyXdgConfig);

        putenv('XDG_CONFIG_HOME');
        unset($_ENV['XDG_CONFIG_HOME']);

        $_SERVER['XDG_CONFIG_HOME'] = $serverOnlyXdgConfig;

        file_put_contents($serverOnlyXdgConfig . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(true);

        self::assertSame(
            $this->getValidAuditCommand(true),
            $this->executor->getExecutedCommand(1),
            'Server-only environment values must not affect Windows audit.',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWindowsAuditUsesCaseInsensitiveEnvironmentPrecedence(): void
    {
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            define('PHP_WINDOWS_VERSION_BUILD', 1);
        }

        $environmentConfig = $this->cwd . DIRECTORY_SEPARATOR . 'environment-config';
        $processConfig = $this->cwd . DIRECTORY_SEPARATOR . 'process-config';

        $this->sfs->mkdir([$environmentConfig, $processConfig]);

        putenv("XDG_CONFIG_HOME={$processConfig}");
        unset($_ENV['XDG_CONFIG_HOME']);

        $_ENV['xdg_config_home'] = new class ($environmentConfig) implements \Stringable {
            public function __construct(private readonly string $value) {}

            public function __toString(): string
            {
                return $this->value;
            }
        };
        $_SERVER['XDG_CONFIG_HOME'] = $processConfig;

        file_put_contents($environmentConfig . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED->getMessage(
                $environmentConfig . DIRECTORY_SEPARATOR . '.npmrc',
                'omit=optional',
            ),
        );

        $this->getManager()->audit(true);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWindowsAuditUsesProcessEnvironmentAndHomeDriveFallback(): void
    {
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            define('PHP_WINDOWS_VERSION_BUILD', 1);
        }

        $xdgConfigHome = $this->cwd . DIRECTORY_SEPARATOR . 'xdg-config';
        $driveOnlyHome = $this->cwd . DIRECTORY_SEPARATOR . 'drive-only-home';
        $homePath = DIRECTORY_SEPARATOR . 'windows-home';
        $home = "{$this->cwd}{$homePath}";

        $this->sfs->mkdir([$xdgConfigHome, $driveOnlyHome, $home]);

        foreach (['HOME', 'USERPROFILE', 'XDG_CONFIG_HOME'] as $name) {
            putenv($name);
        }

        $_ENV = [];
        $_SERVER = [];

        putenv("xdg_config_home={$xdgConfigHome}");
        file_put_contents($driveOnlyHome . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($home . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        putenv("HOMEPATH={$home}");

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(true);

        putenv('HOMEPATH');
        putenv("HOMEDRIVE={$driveOnlyHome}");

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(true);

        putenv("HOMEDRIVE={$this->cwd}");
        putenv("HOMEPATH={$homePath}");

        $path = $home . DIRECTORY_SEPARATOR . '.npmrc';

        try {
            $this->getManager()->audit(true);

            self::fail(
                'Expected the Windows home npmrc configuration to be rejected.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED->getMessage($path, 'omit=optional'),
                $exception->getMessage(),
                'Windows home npmrc must be the rejected file.',
            );
        }

        self::assertSame(
            $this->getValidAuditCommand(true),
            $this->executor->getExecutedCommand(1),
            'The first Windows audit must use the process environment.',
        );
        self::assertSame(
            $this->getValidAuditCommand(true),
            $this->executor->getExecutedCommand(3),
            'The second Windows audit must use the home-drive fallback.',
        );
        self::assertNull(
            $this->executor->getExecutedCommand(4),
            'Rejected home configuration must not execute an audit command.',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWindowsCommandsUseExecutableNameAndNormalizedCustomPath(): void
    {
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            define('PHP_WINDOWS_VERSION_BUILD', 1);
        }

        $this->executor->addExpectedValues(0, '1.4.0');
        $this->manager = $this->getManager();
        $this->manager->validate();

        self::assertSame(
            'bun.exe --version',
            $this->executor->getLastCommand(),
            'Windows version lookup must use the executable name.',
        );

        $this->config = new Config(['run-asset-manager' => true, 'manager-bin' => 'C:/tools/bun.exe']);

        $this->executor->addExpectedValues(0, '1.4.0');
        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        self::assertSame(
            0,
            $this->getManager()->run(),
            'Successful execution must return a zero exit code.',
        );
        self::assertSame(
            'C:\\tools\\bun.exe install',
            $this->executor->getLastCommand(),
            'The custom Windows binary path must use backslashes.',
        );
    }

    protected function getManager(): BunManager
    {
        return new BunManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getUnsupportedVersion(): string
    {
        return '1.3.9';
    }

    protected function getValidAuditCommand(bool $noDev): string
    {
        $binary = Platform::isWindows() ? 'bun.exe' : 'bun';

        return $binary . ' audit --json' . ($noDev ? ' --prod' : '');
    }

    protected function getValidInstallCommand(): string
    {
        return Platform::isWindows() ? 'bun.exe install' : 'bun install';
    }

    protected function getValidLockPackageName(): string
    {
        return 'bun.lock';
    }

    protected function getValidName(): string
    {
        return 'bun';
    }

    protected function getValidUpdateCommand(): string
    {
        return Platform::isWindows() ? 'bun.exe update' : 'bun update';
    }

    protected function getValidVersion(): string
    {
        return '1.4.0';
    }

    protected function getValidVersionCommand(): string
    {
        return Platform::isWindows() ? 'bun.exe --version' : 'bun --version';
    }

    protected function getValidVersionConstraint(): string
    {
        return '^1.4.0';
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['HOME', 'USERPROFILE', 'XDG_CONFIG_HOME'] as $name) {
            $this->environmentState[$name] = [
                'process' => getenv($name),
                'envExists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'serverExists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
            ];
        }

        putenv("HOME={$this->cwd}");

        $_ENV['HOME'] = $this->cwd;
        $_SERVER['HOME'] = $this->cwd;

        putenv('XDG_CONFIG_HOME');
        unset($_ENV['XDG_CONFIG_HOME'], $_SERVER['XDG_CONFIG_HOME']);
    }

    protected function tearDown(): void
    {
        foreach ($this->environmentState as $name => $values) {
            putenv(false === $values['process'] ? $name : $name . '=' . $values['process']);

            if ($values['envExists']) {
                $_ENV[$name] = $values['env'];
            } else {
                unset($_ENV[$name]);
            }

            if ($values['serverExists']) {
                $_SERVER[$name] = $values['server'];
            } else {
                unset($_SERVER[$name]);
            }
        }

        $this->environmentState = [];

        parent::tearDown();
    }
}
