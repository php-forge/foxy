<?php

declare(strict_types=1);

namespace Foxy\Tests\Config;

use Composer\{Composer, Config};
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Exception;
use Foxy\Config\ConfigBuilder;
use Foxy\Exception\RuntimeException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Seld\JsonLint\ParsingException;

use function getenv;
use function putenv;
use function sprintf;
use function str_starts_with;
use function strpos;
use function substr;

final class ConfigTest extends TestCase
{
    private Composer|MockObject|null $composer = null;
    private Config|MockObject|null $composerConfig = null;
    private IOInterface|MockObject|null $io = null;
    private MockObject|RootPackageInterface|null $package = null;

    public static function getDataForGetArrayConfig(): array
    {
        return [['foo', [], []], ['foo', [42], [42]], ['foo', [42], [], ['foo' => [42]]]];
    }

    public static function getDataForGetConfig(): array
    {
        return [
            ['foo', 42, 42],
            ['bar', 'foo', 'empty'],
            ['baz', false, true],
            ['test', 0, 0],
            ['manager-bar', 23, 0],
            ['manager-baz', 0, 0],
            ['global-composer-foo', 90, 0],
            ['global-composer-bar', 70, 0],
            ['global-config-foo', 23, 0],
            ['env-boolean', false, true, 'FOXY__ENV_BOOLEAN=false'],
            ['env-integer', -32, 0, 'FOXY__ENV_INTEGER=-32'],
            ['env-json', ['foo' => 'bar'], [], 'FOXY__ENV_JSON="{"foo": "bar"}"'],
            ['env-json-array', [['foo' => 'bar']], [], 'FOXY__ENV_JSON_ARRAY="[{"foo": "bar"}]"'],
            ['env-string', 'baz', 'foo', 'FOXY__ENV_STRING=baz'],
            ['test-p1', 'def', 'def', null, []],
            ['test-p1', 'def', 'def', null, ['test-p1' => 'ok']],
            ['test-p1', 'ok', null, null, ['test-p1' => 'ok']],
        ];
    }

    /**
     * @dataProvider getDataForGetArrayConfig
     *
     * @param string $key The key.
     * @param array $expected The expected value.
     * @param array $default The default value.
     * @param array $defaults The configured default values.
     *
     * @throws ParsingException
     */
    public function testGetArrayConfig(string $key, array $expected, array $default, array $defaults = []): void
    {
        $config = ConfigBuilder::build($this->composer, $defaults, $this->io);

        self::assertSame(
            $expected,
            $config->getArray($key, $default),
        );
    }

    /**
     * @dataProvider getDataForGetConfig
     *
     * @param string $key The key.
     * @param mixed $expected The expected value.
     * @param mixed $default The default value.
     * @param string|null $env The env variable.
     * @param array $defaults The configured default values.
     *
     * @throws ParsingException
     */
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

        $this->composerConfig->expects(self::any())->method('has')->with('home')->willReturn(true);
        $this->composerConfig->expects(self::any())->method('get')->with('home')->willReturn($globalPath);

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
            $this->io->expects(self::atLeast(2))->method('isDebug')->willReturn(true);

            $globalLogComposer = false;
            $globalLogConfig = false;

            $this->io
                ->expects(self::atLeastOnce())
                ->method('writeError')
                ->willReturnCallback(
                    static function ($message) use ($globalPath, &$globalLogComposer, &$globalLogConfig): void {
                        if (sprintf('Loading Foxy config in file %s/composer.json', $globalPath)) {
                            $globalLogComposer = true;
                        }

                        if (sprintf('Loading Foxy config in file %s/config.json', $globalPath)) {
                            $globalLogConfig = true;
                        }
                    },
                );
        }

        $config = ConfigBuilder::build($this->composer, $defaults, $this->io);
        $value = $config->get($key, $default);

        // remove env variables
        if (null !== $env) {
            $envKey = substr($env, 0, strpos($env, '='));
            putenv($envKey);

            self::assertFalse(
                getenv($envKey),
            );
        }

        self::assertTrue(
            $globalLogComposer,
        );
        self::assertTrue(
            $globalLogConfig,
        );
        self::assertSame(
            $expected,
            $value,
        );
        self::assertSame(
            $expected,
            $config->get($key, $default),
        );
    }

    /**
     * @throws Exception|ParsingException
     */
    public function testGetEnvConfigWithInvalidJson(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The "FOXY__ENV_JSON" environment variable isn\'t a valid JSON');

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
        );

        if (null === $ex) {
            throw new Exception('The expected exception was not thrown');
        }

        throw $ex;
    }

    protected function setUp(): void
    {
        $this->composer = $this->createMock(Composer::class);
        $this->composerConfig = $this->createMock(Config::class);
        $this->io = $this->createMock(IOInterface::class);
        $this->package = $this->createMock(RootPackageInterface::class);

        $this->composer->expects(self::any())->method('getPackage')->willReturn($this->package);
        $this->composer->expects(self::any())->method('getConfig')->willReturn($this->composerConfig);
    }
}
