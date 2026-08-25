<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\Util\ProcessExecutor;
use Foxy\Asset\YarnManager;

use function array_key_exists;
use function file_put_contents;
use function getenv;
use function putenv;

use const DIRECTORY_SEPARATOR;

final class YarnAssetManagerTest extends AssetManager
{
    private const AUDIT_ENVIRONMENT_VARIABLES = [
        'YARN_NPM_AUDIT_EXCLUDE_PACKAGES',
        'YARN_NPM_AUDIT_IGNORE_ADVISORIES',
    ];

    public function testAuditRestoresFilteringEnvironmentAfterExecution(): void
    {
        $state = self::captureEnvironment(self::AUDIT_ENVIRONMENT_VARIABLES);

        try {
            $excludeVariable = self::AUDIT_ENVIRONMENT_VARIABLES[0];
            $ignoreVariable = self::AUDIT_ENVIRONMENT_VARIABLES[1];

            putenv($excludeVariable . '=process-original');
            $_ENV[$excludeVariable] = 'env-original';
            unset($_SERVER[$excludeVariable]);

            putenv($ignoreVariable);
            unset($_ENV[$ignoreVariable]);
            $_SERVER[$ignoreVariable] = 'server-original';

            file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'yarn.lock', '{}');

            $position = 0;
            $executor = $this->createMock(ProcessExecutor::class);
            $executor
                ->expects(self::exactly(2))
                ->method('execute')
                ->willReturnCallback(
                    static function (mixed $command, mixed &$output = null) use (&$position): int {
                        if (0 === $position++) {
                            $output = '4.18.0';

                            return 0;
                        }

                        foreach (self::AUDIT_ENVIRONMENT_VARIABLES as $variable) {
                            self::assertSame('__FOXY_AUDIT_NO_MATCH__', getenv($variable));
                            self::assertSame('__FOXY_AUDIT_NO_MATCH__', $_ENV[$variable]);
                            self::assertSame('__FOXY_AUDIT_NO_MATCH__', $_SERVER[$variable]);
                        }

                        $output = '{}';

                        return 0;
                    },
                );
            $executor->expects(self::once())->method('getErrorOutput')->willReturn('');

            $manager = new YarnManager($this->io, $this->config, $executor, $this->fs, $this->fallback);
            $manager->audit(false);

            self::assertSame('process-original', getenv($excludeVariable));
            self::assertSame('env-original', $_ENV[$excludeVariable]);
            self::assertArrayNotHasKey($excludeVariable, $_SERVER);
            self::assertFalse(getenv($ignoreVariable));
            self::assertArrayNotHasKey($ignoreVariable, $_ENV);
            self::assertSame('server-original', $_SERVER[$ignoreVariable]);
        } finally {
            self::restoreEnvironment($state);
        }
    }

    public function testAuditRestoresFilteringEnvironmentWhenExecutionFails(): void
    {
        $state = self::captureEnvironment(self::AUDIT_ENVIRONMENT_VARIABLES);

        try {
            foreach (self::AUDIT_ENVIRONMENT_VARIABLES as $variable) {
                putenv($variable);
                unset($_ENV[$variable], $_SERVER[$variable]);
            }

            file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'yarn.lock', '{}');

            $position = 0;
            $executor = $this->createMock(ProcessExecutor::class);
            $executor
                ->expects(self::exactly(2))
                ->method('execute')
                ->willReturnCallback(
                    static function (mixed $command, mixed &$output = null) use (&$position): int {
                        if (0 === $position++) {
                            $output = '4.18.0';

                            return 0;
                        }

                        foreach (self::AUDIT_ENVIRONMENT_VARIABLES as $variable) {
                            self::assertSame('__FOXY_AUDIT_NO_MATCH__', getenv($variable));
                            self::assertSame('__FOXY_AUDIT_NO_MATCH__', $_ENV[$variable]);
                            self::assertSame('__FOXY_AUDIT_NO_MATCH__', $_SERVER[$variable]);
                        }

                        throw new \RuntimeException('Audit execution failed.');
                    },
                );

            $manager = new YarnManager($this->io, $this->config, $executor, $this->fs, $this->fallback);

            try {
                $manager->audit(false);
                self::fail('Expected the audit process to fail.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Audit execution failed.', $exception->getMessage());
            }

            foreach (self::AUDIT_ENVIRONMENT_VARIABLES as $variable) {
                self::assertFalse(getenv($variable));
                self::assertArrayNotHasKey($variable, $_ENV);
                self::assertArrayNotHasKey($variable, $_SERVER);
            }
        } finally {
            self::restoreEnvironment($state);
        }
    }

    public function testIsInstalledRequiresLockFileWithPlugAndPlayState(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', '{}');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . '.pnp.cjs', '');

        self::assertFalse($this->manager->isInstalled());
    }

    public function testIsInstalledRequiresPackageFileWithPlugAndPlayState(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'yarn.lock', '');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . '.pnp.cjs', '');

        self::assertFalse($this->manager->isInstalled());
    }

    public function testIsInstalledWithPlugAndPlayState(): void
    {
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'package.json', '{}');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . 'yarn.lock', '');
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . '.pnp.cjs', '');

        self::assertTrue($this->manager->isInstalled());
    }

    protected function getManager(): YarnManager
    {
        return new YarnManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getUnsupportedVersion(): string
    {
        return '4.17.1';
    }

    protected function getValidAuditCommand(bool $noDev): string
    {
        $command = 'yarn npm audit --all --recursive --json --no-deprecations --severity info';

        return $command . ($noDev ? ' --environment production' : '');
    }

    protected function getValidInstallCommand(): string
    {
        return 'yarn install';
    }

    protected function getValidLockPackageName(): string
    {
        return 'yarn.lock';
    }

    protected function getValidName(): string
    {
        return 'yarn';
    }

    protected function getValidUpdateCommand(): string
    {
        return 'yarn up';
    }

    protected function getValidVersion(): string
    {
        return '4.18.0';
    }

    protected function getValidVersionCommand(): string
    {
        return 'yarn --version';
    }

    protected function getValidVersionConstraint(): string
    {
        return '^4.18.0';
    }

    /**
     * @param list<string> $variables
     *
     * @return array<string, array{
     *     process: string|false,
     *     envExists: bool,
     *     env: mixed,
     *     serverExists: bool,
     *     server: mixed,
     * }>
     */
    private static function captureEnvironment(array $variables): array
    {
        $state = [];

        foreach ($variables as $variable) {
            $state[$variable] = [
                'process' => getenv($variable),
                'envExists' => array_key_exists($variable, $_ENV),
                'env' => $_ENV[$variable] ?? null,
                'serverExists' => array_key_exists($variable, $_SERVER),
                'server' => $_SERVER[$variable] ?? null,
            ];
        }

        return $state;
    }

    /**
     * @param array<string, array{
     *     process: string|false,
     *     envExists: bool,
     *     env: mixed,
     *     serverExists: bool,
     *     server: mixed,
     * }> $state
     */
    private static function restoreEnvironment(array $state): void
    {
        foreach ($state as $variable => $values) {
            putenv(false === $values['process'] ? $variable : $variable . '=' . $values['process']);

            if ($values['envExists']) {
                $_ENV[$variable] = $values['env'];
            } else {
                unset($_ENV[$variable]);
            }

            if ($values['serverExists']) {
                $_SERVER[$variable] = $values['server'];
            } else {
                unset($_SERVER[$variable]);
            }
        }
    }
}
