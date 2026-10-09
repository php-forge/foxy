<?php

declare(strict_types=1);

namespace Foxy\Tests\Config;

use Composer\{Composer, Config};
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Exception;
use Foxy\Config\Config as FoxyConfig;
use Foxy\Config\ConfigBuilder;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Tests\Provider\ConfigProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Seld\JsonLint\ParsingException;
use Symfony\Component\Filesystem\Filesystem;

use function getenv;
use function putenv;
use function sprintf;
use function str_starts_with;
use function strpos;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Unit tests for {@see ConfigBuilder} configuration merging and {@see FoxyConfig} value resolution.
 *
 * {@see ConfigProvider} for test case data providers.
 */
final class ConfigTest extends TestCase
{
    private Composer|MockObject|null $composer = null;
    private Config|MockObject|null $composerConfig = null;
    private IOInterface|MockObject|null $io = null;
    private MockObject|RootPackageInterface|null $package = null;

    /**
     * @throws ParsingException
     */
    public function testBuildIgnoresNonArrayGlobalConfig(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('foxy_config_test_', true);

        $filesystem = new Filesystem();

        $filesystem->mkdir($directory);

        file_put_contents($directory . '/composer.json', '{"config":{"foxy":"invalid"}}');

        $this->composerConfig
            ->expects(self::exactly(2))
            ->method('get')
            ->with('home')
            ->willReturn($directory);
        $this->package
            ->expects(self::once())
            ->method('getConfig')
            ->willReturn([]);

        try {
            $config = ConfigBuilder::build($this->composer, [], $this->io);

            self::assertSame(
                'fallback',
                $config->get('missing', 'fallback'),
                'Missing keys must return the supplied fallback.',
            );
        } finally {
            $filesystem->remove($directory);
        }
    }

    /**
     * @param string $key The key.
     * @param array $expected The expected value.
     * @param array $default The default value.
     * @param array $defaults The configured default values.
     *
     * @throws ParsingException
     */
    #[DataProviderExternal(ConfigProvider::class, 'arrayConfigValues')]
    public function testGetArrayConfig(string $key, array $expected, array $default, array $defaults = []): void
    {
        $config = ConfigBuilder::build($this->composer, $defaults, $this->io);

        self::assertSame(
            $expected,
            $config->getArray($key, $default),
            'Array configuration must match the expected value.',
        );
    }

    /**
     * @param string $key The key.
     * @param mixed $expected The expected value.
     * @param mixed $default The default value.
     * @param string|null $env The env variable.
     * @param array $defaults The configured default values.
     *
     * @throws ParsingException
     */
    #[DataProviderExternal(ConfigProvider::class, 'configValues')]
    public function testGetConfig(
        string $key,
        mixed $expected,
        mixed $default = null,
        string|null $env = null,
        array $defaults = [],
    ): void {
        // add env variables
        if (null !== $env) {
            putenv($env);
        }

        $globalLogComposer = true;
        $globalLogConfig = true;

        $globalPath = realpath(__DIR__ . '/../Fixtures/package/global');

        $this->composerConfig
            ->expects(self::any())
            ->method('has')
            ->with('home')
            ->willReturn(true);
        $this->composerConfig
            ->expects(self::any())
            ->method('get')
            ->with('home')
            ->willReturn($globalPath);
        $this->package
            ->expects(self::any())
            ->method('getConfig')
            ->willReturn(
                [
                    'foxy' => [
                        'bar' => 'foo',
                        'baz' => false,
                        'env-foo' => 55,
                        'manager' => 'quill',
                        'manager-bar' => [
                            'peter' => 42,
                            'quill' => 23,
                        ],
                        'manager-baz' => [
                            'peter' => 42,
                        ],
                    ],
                ],
            );

        if (str_starts_with($key, 'global-')) {
            $this->io
                ->expects(self::atLeast(2))
                ->method('isDebug')
                ->willReturn(true);

            $globalLogComposer = false;
            $globalLogConfig = false;

            $this->io
                ->expects(self::atLeastOnce())
                ->method('writeError')
                ->willReturnCallback(
                    static function ($message) use ($globalPath, &$globalLogComposer, &$globalLogConfig): void {
                        $expectedComposerMessage = sprintf('Loading Foxy config in file %s/composer.json', $globalPath);
                        $expectedConfigMessage = sprintf('Loading Foxy config in file %s/config.json', $globalPath);

                        if ($message === $expectedComposerMessage) {
                            $globalLogComposer = true;
                        }

                        if ($message === $expectedConfigMessage) {
                            $globalLogConfig = true;
                        }
                    },
                );
        }

        $config = ConfigBuilder::build($this->composer, $defaults, $this->io);

        $value = $config->get($key, $default);

        // remove env variables
        if (null !== $env) {
            $envKeyPos = strpos($env, '=');
            $envKey = $envKeyPos !== false ? substr($env, 0, $envKeyPos) : '';
            putenv($envKey);

            self::assertFalse(
                getenv($envKey),
                'Environment variable must be unset after reading.',
            );
        }

        self::assertTrue(
            $globalLogComposer,
            'Composer configuration loading must be logged.',
        );
        self::assertTrue(
            $globalLogConfig,
            'Global configuration loading must be logged.',
        );
        self::assertSame(
            $expected,
            $value,
            'Resolved configuration value must match the expected value.',
        );
        self::assertSame(
            $expected,
            $config->get($key, $default),
            'Repeated reads must return the same configuration value.',
        );
    }

    /**
     * @throws Exception|ParsingException
     */
    public function testGetEnvConfigWithInvalidJson(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::CONFIG_ENV_JSON_INVALID->getMessage('FOXY__ENV_JSON'),
        );

        putenv('FOXY__ENV_JSON="{"foo"}"');

        $config = ConfigBuilder::build($this->composer, [], $this->io);

        $ex = null;

        try {
            $config->get('env-json');
        } catch (Exception $e) {
            $ex = $e;
        }

        putenv('FOXY__ENV_JSON');

        self::assertFalse(
            getenv('FOXY__ENV_JSON'),
            'Invalid JSON environment variable must be unset.',
        );

        if (null === $ex) {
            throw new RuntimeException(
                'The expected exception was not thrown',
            );
        }

        throw $ex;
    }

    #[DataProviderExternal(ConfigProvider::class, 'enabledValues')]
    public function testIsEnabled(mixed $value, bool $expected): void
    {
        $config = new FoxyConfig(['feature' => $value]);

        self::assertSame(
            $expected,
            $config->isEnabled('feature'),
            'Feature state must match the configured value.',
        );
    }

    public function testResolvedManagerSelectsManagerSpecificDefaults(): void
    {
        $config = new FoxyConfig(
            [],
            ['manager-version' => ['npm' => '>=10.9.8', 'yarn' => '^4.18.0']],
        );

        self::assertNull(
            $config->get('manager-version'),
            'Manager-specific default must remain unresolved initially.',
        );

        $config->setResolvedManager('yarn');

        self::assertSame(
            '^4.18.0',
            $config->get('manager-version'),
            'Resolved manager must select its configured version constraint.',
        );
    }

    protected function setUp(): void
    {
        $this->composer = $this->createMock(Composer::class);
        $this->composerConfig = $this->createMock(Config::class);
        $this->io = $this->createMock(IOInterface::class);
        $this->package = $this->createMock(RootPackageInterface::class);

        $this->composer
            ->expects(self::any())
            ->method('getPackage')
            ->willReturn($this->package);
        $this->composer
            ->expects(self::any())
            ->method('getConfig')
            ->willReturn($this->composerConfig);
    }
}
