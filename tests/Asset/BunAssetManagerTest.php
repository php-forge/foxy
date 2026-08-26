<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\Util\Platform;
use Foxy\Asset\BunManager;
use Foxy\Config\Config;
use Foxy\Exception\RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunInSeparateProcess};
use Xepozz\InternalMocker\MockerState;

use function array_key_exists;
use function define;
use function defined;
use function file_put_contents;
use function getenv;
use function putenv;
use function sprintf;

use const DIRECTORY_SEPARATOR;

final class BunAssetManagerTest extends AssetManager
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

    public static function benignAuditConfigurations(): array
    {
        return [
            'escaped non-scope npmrc value' => [
                '.npmrc',
                '"cache" = "C:\\npm-cache"' . "\nomit=dev\n",
                true,
            ],
            'unrelated Bun install settings and table' => [
                'bunfig.toml',
                "[install]\nnotoptional = false\nnotinstall = { optional = false }\n"
                . "[other]\noptional = false\n",
                false,
            ],
            'TOML comment containing an unmatched container' => [
                'bunfig.toml',
                "[install]\nnote = [\"\"] # [\noptional = true\n",
                false,
            ],
            'escaped TOML string before an unmatched comment container' => [
                'bunfig.toml',
                "[install]\nnote = [\"escaped \\\" quote\"] # [\noptional = true\n",
                false,
            ],
            'TOML string containing a comment character' => [
                'bunfig.toml',
                "[install]\nnote = [\"value#suffix\"]\noptional = true\n",
                false,
            ],
        ];
    }

    public static function restrictiveAuditConfigurations(): array
    {
        return [
            'npmrc development omission for a full audit' => ['.npmrc', 'omit=dev', false, 'omit=dev'],
            'npmrc optional omission for a production audit' => ['.npmrc', 'omit[]=optional', true, 'omit=optional'],
            'dynamic npmrc omission' => ['.npmrc', 'omit=${AUDIT_OMIT}', true, 'dynamic omit=${AUDIT_OMIT}'],
            'BOM-prefixed npmrc omission' => ['.npmrc', "\xEF\xBB\xBFomit=peer", true, 'omit=peer'],
            'double-quoted npmrc omission key' => ['.npmrc', '"omit"=optional', true, 'omit=optional'],
            'single-quoted npmrc omission key' => ['.npmrc', "'omit'=peer", true, 'omit=peer'],
            'quoted npmrc omission array key' => ['.npmrc', '"omit[]"=optional', true, 'omit=optional'],
            'multiline uppercase npmrc omission' => [
                '.npmrc',
                "\n # comment\n ; comment\nstrict-ssl\nregistry=https://registry.npmjs.org/\n"
                . " OMIT = [\"OPTIONAL\"]\n",
                true,
                'omit=optional',
            ],
            'padded dynamic npmrc omission' => [
                '.npmrc',
                'omit =  ${AUDIT_OMIT}  ',
                true,
                'dynamic omit=${AUDIT_OMIT}',
            ],
            'bunfig production mode for a full audit' => [
                'bunfig.toml',
                "[install]\nproduction = true\n",
                false,
                'install.production=true',
            ],
            'bunfig development exclusion for a full audit' => [
                'bunfig.toml',
                "[install]\ndev = false\n",
                false,
                'install.dev=false',
            ],
            'dotted bunfig optional exclusion' => [
                'bunfig.toml',
                'install.optional = false',
                true,
                'install.optional=false',
            ],
            'bunfig peer exclusion' => [
                'bunfig.toml',
                "[install]\npeer = false\n",
                true,
                'install.peer=false',
            ],
            'BOM-prefixed bunfig optional exclusion' => [
                'bunfig.toml',
                "\xEF\xBB\xBF[install]\noptional = false\n",
                true,
                'install.optional=false',
            ],
            'comment after bunfig install table' => [
                'bunfig.toml',
                "[install] # comment\noptional = false\n",
                true,
                'install.optional=false',
            ],
            'quoted bunfig install table' => [
                'bunfig.toml',
                "[\"install\"]\noptional = false\n",
                true,
                'install.optional=false',
            ],
            'double-quoted bunfig install table with comment' => [
                'bunfig.toml',
                "[\"install\"] # comment\noptional = false\n",
                true,
                'install.optional=false',
            ],
            'single-quoted bunfig install table with comment' => [
                'bunfig.toml',
                "['install'] # comment\noptional = false\n",
                true,
                'install.optional=false',
            ],
            'spaced bunfig install table' => [
                'bunfig.toml',
                "[ install ]\noptional = false\n",
                true,
                'install.optional=false',
            ],
            'blank line before bunfig restriction' => [
                'bunfig.toml',
                "\n[install]\noptional = false\n",
                true,
                'install.optional=false',
            ],
            'double-BOM-prefixed bunfig optional exclusion' => [
                'bunfig.toml',
                "\xEF\xBB\xBF\xEF\xBB\xBF[install]\noptional = false\n",
                true,
                'install.optional=false',
            ],
        ];
    }

    #[DataProvider('benignAuditConfigurations')]
    public function testAuditAcceptsBenignConfiguration(string $file, string $contents, bool $noDev): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . $file, $contents);
        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit($noDev);

        self::assertSame($this->getValidAuditCommand($noDev), $this->executor->getExecutedCommand(1));
    }

    public function testAuditAcceptsBenignHomeAndXdgConfigurations(): void
    {
        $xdgConfigHome = $this->cwd . DIRECTORY_SEPARATOR . 'xdg-config';
        $this->sfs->mkdir($xdgConfigHome);
        putenv('XDG_CONFIG_HOME=' . $xdgConfigHome . DIRECTORY_SEPARATOR);
        $_ENV['XDG_CONFIG_HOME'] = $xdgConfigHome . DIRECTORY_SEPARATOR;
        $_SERVER['XDG_CONFIG_HOME'] = $xdgConfigHome . DIRECTORY_SEPARATOR;

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
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

        self::assertSame($this->getValidAuditCommand(false), $this->executor->getExecutedCommand(1));
    }

    #[DataProvider('restrictiveAuditConfigurations')]
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
            self::fail('Expected a restrictive Bun configuration to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                sprintf(
                    'The Bun audit cannot guarantee the requested dependency scope because "%s" declares "%s".',
                    $this->cwd . DIRECTORY_SEPARATOR . $file,
                    $setting,
                ),
                $exception->getMessage(),
            );
        }

        self::assertNull($this->executor->getExecutedCommand(0));
    }

    #[DataProvider('unverifiableAuditConfigurations')]
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
            self::fail('Expected an unverifiable Bun configuration to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                sprintf('The Bun audit cannot verify dependency scope in "%s": %s.', $path, $reason),
                $exception->getMessage(),
            );
        }

        self::assertNull($this->executor->getExecutedCommand(0));
    }

    public function testAuditIgnoresAnEnvironmentVariableThatTheManagerProcessWillDrop(): void
    {
        $home = $this->cwd . DIRECTORY_SEPARATOR . 'home';
        $droppedXdgConfig = $this->cwd . DIRECTORY_SEPARATOR . 'dropped-xdg-config';
        $this->sfs->mkdir([$home, $droppedXdgConfig]);
        putenv('HOME=' . $home);
        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;
        putenv('XDG_CONFIG_HOME=' . $droppedXdgConfig);
        unset($_ENV['XDG_CONFIG_HOME'], $_SERVER['XDG_CONFIG_HOME']);
        file_put_contents($droppedXdgConfig . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(true);

        self::assertSame($this->getValidAuditCommand(true), $this->executor->getExecutedCommand(1));
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

        self::assertSame($this->getValidAuditCommand(true), $this->executor->getExecutedCommand(1));
    }

    public function testAuditIgnoresNonScalarEnvironmentValues(): void
    {
        $home = $this->cwd . DIRECTORY_SEPARATOR . 'home';
        $this->sfs->mkdir($home);
        putenv('HOME=' . $home);
        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;
        $_ENV['XDG_CONFIG_HOME'] = [];
        file_put_contents($home . DIRECTORY_SEPARATOR . '.bunfig.toml', "[install]\noptional = false\n");
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($home . DIRECTORY_SEPARATOR . '.bunfig.toml');

        $this->getManager()->audit(true);
    }

    public function testAuditNoDevAllowsDevelopmentOnlyRestrictions(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . '.npmrc', 'omit=dev');
        file_put_contents(
            $this->cwd . DIRECTORY_SEPARATOR . 'bunfig.toml',
            "[install]\nproduction = true\ndev = false\noptional = true\npeer = true\n",
        );
        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(true);

        self::assertSame($this->getValidAuditCommand(true), $this->executor->getExecutedCommand(1));
    }

    public function testAuditNormalizesConfigurationDirectorySeparators(): void
    {
        $xdgConfigHome = $this->cwd . DIRECTORY_SEPARATOR . 'xdg-config';
        $this->sfs->mkdir($xdgConfigHome);
        putenv('XDG_CONFIG_HOME=' . $xdgConfigHome . DIRECTORY_SEPARATOR);
        $_ENV['XDG_CONFIG_HOME'] = $xdgConfigHome . DIRECTORY_SEPARATOR;
        $_SERVER['XDG_CONFIG_HOME'] = $xdgConfigHome . DIRECTORY_SEPARATOR;
        file_put_contents($xdgConfigHome . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        try {
            $this->getManager()->audit(true);
            self::fail('Expected the normalized XDG npmrc configuration to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                '"' . $xdgConfigHome . DIRECTORY_SEPARATOR . '.npmrc"',
                $exception->getMessage(),
            );
        }

        self::assertNull($this->executor->getExecutedCommand(0));
    }

    public function testAuditPrefersHomeOverUserProfile(): void
    {
        $home = $this->cwd . DIRECTORY_SEPARATOR . 'home';
        $userProfile = $this->cwd . DIRECTORY_SEPARATOR . 'user-profile';
        $this->sfs->mkdir([$home, $userProfile]);

        putenv('HOME=' . $home);
        putenv('USERPROFILE=' . $userProfile);
        $_ENV['HOME'] = $home;
        $_ENV['USERPROFILE'] = $userProfile;
        $_SERVER['HOME'] = $home;
        $_SERVER['USERPROFILE'] = $userProfile;

        file_put_contents($home . DIRECTORY_SEPARATOR . '.bunfig.toml', "[install]\noptional = false\n");
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($home . DIRECTORY_SEPARATOR . '.bunfig.toml');

        $this->getManager()->audit(true);
    }

    public function testAuditPreservesQuotedTomlCharactersDuringPreflight(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        file_put_contents(
            $this->cwd . DIRECTORY_SEPARATOR . 'bunfig.toml',
            "[install]\nnote = [\"]\", '}#', \"escaped \\\"#\"] # comment\noptional = true\n",
        );
        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(false);

        self::assertSame($this->getValidAuditCommand(false), $this->executor->getExecutedCommand(1));
    }

    public function testAuditRejectsConfigurationDirectory(): void
    {
        $path = $this->cwd . DIRECTORY_SEPARATOR . '.npmrc';
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        $this->sfs->mkdir($path);

        try {
            $this->getManager()->audit(false);
            self::fail('Expected a Bun configuration directory to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                sprintf('The Bun audit configuration "%s" cannot be read.', $path),
                $exception->getMessage(),
            );
        }

        self::assertNull($this->executor->getExecutedCommand(0));
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
            self::fail('Expected a Bun configuration read failure to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                sprintf('The Bun audit configuration "%s" cannot be read.', $path),
                $exception->getMessage(),
            );
        }

        self::assertNull($this->executor->getExecutedCommand(0));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAuditUsesProcessEnvironmentWhenServerIntersectionIsEmpty(): void
    {
        $xdgConfigHome = $this->cwd . DIRECTORY_SEPARATOR . 'xdg-config';
        $this->sfs->mkdir($xdgConfigHome);
        putenv('XDG_CONFIG_HOME=' . $xdgConfigHome);
        $_ENV = [];
        $_SERVER = [];
        file_put_contents($xdgConfigHome . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($xdgConfigHome . DIRECTORY_SEPARATOR . '.npmrc');

        $this->getManager()->audit(true);
    }

    public function testAuditUsesTheSameEnvironmentPrecedenceAsTheManagerProcess(): void
    {
        $environmentConfig = $this->cwd . DIRECTORY_SEPARATOR . 'environment-config';
        $processConfig = $this->cwd . DIRECTORY_SEPARATOR . 'process-config';
        $this->sfs->mkdir([$environmentConfig, $processConfig]);
        putenv('XDG_CONFIG_HOME=' . $processConfig);
        $_ENV['XDG_CONFIG_HOME'] = $environmentConfig;
        $_SERVER['XDG_CONFIG_HOME'] = $processConfig;
        file_put_contents($environmentConfig . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($environmentConfig . DIRECTORY_SEPARATOR . '.npmrc');

        $this->getManager()->audit(true);
    }

    public function testAuditValidatesBothXdgAndRootNpmrcConfigurations(): void
    {
        $xdgConfigHome = $this->cwd . DIRECTORY_SEPARATOR . 'xdg-config';
        $this->sfs->mkdir($xdgConfigHome);
        putenv('XDG_CONFIG_HOME=' . $xdgConfigHome);
        $_ENV['XDG_CONFIG_HOME'] = $xdgConfigHome;
        $_SERVER['XDG_CONFIG_HOME'] = $xdgConfigHome;
        file_put_contents($xdgConfigHome . DIRECTORY_SEPARATOR . '.npmrc', 'registry=https://registry.npmjs.org/');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->cwd . DIRECTORY_SEPARATOR . '.npmrc');

        $this->getManager()->audit(true);
    }

    public function testIgnoresLegacyBinaryLockFile(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lockb', 'legacy');

        self::assertFalse($this->manager->hasLockFile());
    }

    public function testIgnoresLegacyBinaryLockFileInConfiguredRootDirectory(): void
    {
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . 'web';
        $this->sfs->mkdir($rootPackageDir);
        $this->config = new Config([], ['root-package-json-dir' => $rootPackageDir]);
        $this->manager = $this->getManager();

        file_put_contents($rootPackageDir . DIRECTORY_SEPARATOR . 'bun.lockb', 'legacy');

        self::assertFalse($this->manager->hasLockFile());
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
        putenv('XDG_CONFIG_HOME=' . $droppedXdgConfig);
        unset($_ENV['XDG_CONFIG_HOME'], $_SERVER['XDG_CONFIG_HOME']);
        file_put_contents($droppedXdgConfig . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');
        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(true);

        self::assertSame($this->getValidAuditCommand(true), $this->executor->getExecutedCommand(1));
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

        self::assertSame($this->getValidAuditCommand(true), $this->executor->getExecutedCommand(1));
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
        putenv('XDG_CONFIG_HOME=' . $processConfig);
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
        $this->expectExceptionMessage($environmentConfig . DIRECTORY_SEPARATOR . '.npmrc');

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
        $home = $this->cwd . $homePath;
        $this->sfs->mkdir([$xdgConfigHome, $driveOnlyHome, $home]);

        foreach (['HOME', 'USERPROFILE', 'XDG_CONFIG_HOME'] as $name) {
            putenv($name);
        }

        $_ENV = [];
        $_SERVER = [];
        putenv('xdg_config_home=' . $xdgConfigHome);

        file_put_contents($driveOnlyHome . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($home . DIRECTORY_SEPARATOR . '.npmrc', 'omit=optional');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'bun.lock', '{}');

        putenv('HOMEPATH=' . $home);
        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(true);

        putenv('HOMEPATH');
        putenv('HOMEDRIVE=' . $driveOnlyHome);
        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        $this->getManager()->audit(true);

        putenv('HOMEDRIVE=' . $this->cwd);
        putenv('HOMEPATH=' . $homePath);

        $path = $home . DIRECTORY_SEPARATOR . '.npmrc';

        try {
            $this->getManager()->audit(true);
            self::fail('Expected the Windows home npmrc configuration to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($path, $exception->getMessage());
        }

        self::assertSame($this->getValidAuditCommand(true), $this->executor->getExecutedCommand(1));
        self::assertSame($this->getValidAuditCommand(true), $this->executor->getExecutedCommand(3));
        self::assertNull($this->executor->getExecutedCommand(4));
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

        self::assertSame('bun.exe --version', $this->executor->getLastCommand());

        $this->config = new Config(['run-asset-manager' => true, 'manager-bin' => 'C:/tools/bun.exe']);
        $this->executor->addExpectedValues(0, '1.4.0');
        $this->executor->addExpectedValues(0, 'ASSET MANAGER OUTPUT');

        self::assertSame(0, $this->getManager()->run());
        self::assertSame('C:\\tools\\bun.exe install', $this->executor->getLastCommand());
    }

    public static function unverifiableAuditConfigurations(): array
    {
        return [
            'escaped table key' => [
                'bunfig.toml',
                "[\"in\\u0073tall\"]\noptional = false\n",
                'escape sequences in TOML keys are not supported; use canonical keys',
            ],
            'escaped dependency key' => [
                'bunfig.toml',
                "[install]\n\"option\\u0061l\" = false\n",
                'escape sequences in TOML keys are not supported; use canonical keys',
            ],
            'unquoted escaped dependency key' => [
                'bunfig.toml',
                "[install]\n\\optional = false\n",
                'escape sequences in TOML keys are not supported; use canonical keys',
            ],
            'hex-escaped table and dependency keys' => [
                'bunfig.toml',
                "[\"in\\x73tall\"]\n\"option\\x61l\" = false\n",
                'escape sequences in TOML keys are not supported; use canonical keys',
            ],
            'single-line inline install table' => [
                'bunfig.toml',
                'install = { optional = false }',
                'inline install tables are not supported; use an [install] table',
            ],
            'multiline inline install table' => [
                'bunfig.toml',
                "install = {\n optional = false,\n}\n",
                'inline install tables are not supported; use an [install] table',
            ],
            'array install table' => [
                'bunfig.toml',
                "[[install]]\noptional = false\n",
                'array install tables are not supported; use an [install] table',
            ],
            'multiline basic string in install table' => [
                'bunfig.toml',
                "[install]\nnote = \"\"\"\n[not-a-table]\n\"\"\"\noptional = false\n",
                'multiline strings in [install] are not supported',
            ],
            'multiline literal string in install table' => [
                'bunfig.toml',
                "[install]\nnote = '''\n[not-a-table]\n'''\noptional = false\n",
                'multiline strings in [install] are not supported',
            ],
            'multiline array in install table' => [
                'bunfig.toml',
                "[install]\nnote = [\n  [1],\n]\noptional = false\n",
                'multiline container values in [install] are not supported',
            ],
            'multiline array without assignment whitespace' => [
                'bunfig.toml',
                "[install]\nnote=[\n  1,\n]\n",
                'multiline container values in [install] are not supported',
            ],
            'multiline inline table without assignment whitespace' => [
                'bunfig.toml',
                "[install]\nnote={\n  value = true,\n}\n",
                'multiline container values in [install] are not supported',
            ],
            'mismatched container closer' => [
                'bunfig.toml',
                "[install]\nnote = [}\n",
                'multiline container values in [install] are not supported',
            ],
            'unexpected container closer' => [
                'bunfig.toml',
                "[install]\nnote = []]\n",
                'multiline container values in [install] are not supported',
            ],
            'unterminated string in an install container' => [
                'bunfig.toml',
                "[install]\nnote = [\"unterminated]\noptional = false\n",
                'multiline container values in [install] are not supported',
            ],
            'unterminated literal string in an install container' => [
                'bunfig.toml',
                "[install]\nnote = ['unterminated]\noptional = false\n",
                'multiline container values in [install] are not supported',
            ],
            'UTF-16LE bunfig' => [
                'bunfig.toml',
                "\xFF\xFE[\0i\0n\0s\0t\0a\0l\0l\0]\0\n\0o\0p\0t\0i\0o\0n\0a\0l\0=\0f\0a\0l\0s\0e\0",
                'the configuration must be UTF-8',
            ],
            'UTF-16LE npmrc' => [
                '.npmrc',
                "\xFF\xFEo\0m\0i\0t\0=\0o\0p\0t\0i\0o\0n\0a\0l\0",
                'the configuration must be UTF-8',
            ],
            'hex-escaped npmrc omit key' => [
                '.npmrc',
                '"om\\x69t"=optional',
                'escape sequences in npmrc keys are not supported; use canonical keys',
            ],
            'Unicode-escaped npmrc omit key' => [
                '.npmrc',
                '"om\\u0069t"=optional',
                'escape sequences in npmrc keys are not supported; use canonical keys',
            ],
            'single-quoted escaped npmrc omit key' => [
                '.npmrc',
                "'om\\u0069t'=optional",
                'escape sequences in npmrc keys are not supported; use canonical keys',
            ],
            'indented escaped npmrc omit key' => [
                '.npmrc',
                '  "om\\u0069t"=optional',
                'escape sequences in npmrc keys are not supported; use canonical keys',
            ],
            'hex-escaped npmrc omit value' => [
                '.npmrc',
                'omit="opti\\x6fnal"',
                'escape sequences in npmrc omit values are not supported; use canonical values',
            ],
            'Unicode-escaped npmrc omit value' => [
                '.npmrc',
                'omit="opti\\u006fnal"',
                'escape sequences in npmrc omit values are not supported; use canonical values',
            ],
        ];
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

        putenv('HOME=' . $this->cwd);
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
