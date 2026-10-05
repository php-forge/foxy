<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Foxy\Audit\AuditableAssetManagerInterface;
use Foxy\Config\Config;
use Foxy\Exception\RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;

use function explode;
use function file_put_contents;
use function getcwd;
use function sprintf;

use const DIRECTORY_SEPARATOR;

abstract class AuditableAssetManager extends AssetManager
{
    abstract protected function getValidAuditCommand(bool $noDev): string;

    public static function getAuditCommandData(): array
    {
        return [
            'all dependencies' => [false],
            'production dependencies' => [true],
        ];
    }

    #[DataProvider('getAuditCommandData')]
    public function testAuditBuildsExactCommandWithoutInstallOptions(bool $noDev): void
    {
        $this->config = new Config(
            [
                'manager-options' => ' --install-only ',
                'run-asset-manager' => false,
            ],
        );

        $this->manager = $this->getManager();

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . $this->manager->getLockPackageName(), '{}');

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(1, 'AUDIT OUTPUT');

        self::assertInstanceOf(
            AuditableAssetManagerInterface::class,
            $this->manager,
            'The manager should implement the AuditableAssetManagerInterface',
        );

        $result = $this->manager->audit($noDev);

        self::assertSame(
            1,
            $result->exitCode,
            'The exit code should match the expected value',
        );
        self::assertSame(
            'AUDIT OUTPUT',
            $result->output,
            'The audit output should match the expected value',
        );
        self::assertSame(
            '',
            $result->errorOutput,
            'The error output should be empty',
        );
        self::assertSame(
            $this->getValidVersionCommand(),
            $this->executor->getExecutedCommand(0),
            'The executed command for the version check should match the expected value',
        );
        self::assertSame(
            $this->getValidAuditCommand($noDev),
            $this->executor->getExecutedCommand(1),
            'The executed command for the audit should match the expected value',
        );
        self::assertNull(
            $this->executor->getExecutedCommand(2),
            'There should be no third executed command',
        );
    }

    public function testAuditRejectsMissingLockFileBeforeManagerAuditCommand(): void
    {
        $this->config = new Config(['run-asset-manager' => false]);

        $this->manager = $this->getManager();

        self::assertInstanceOf(
            AuditableAssetManagerInterface::class,
            $this->manager,
            'The manager should implement the AuditableAssetManagerInterface',
        );

        try {
            $this->manager->audit(false);

            self::fail(
                'Expected the audit to reject a missing lock file.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                sprintf(
                    'The %s lock file "%s" was not found.',
                    $this->manager->getName(),
                    $this->cwd . DIRECTORY_SEPARATOR . $this->manager->getLockPackageName(),
                ),
                $exception->getMessage(),
                'The exception message should match the expected value',
            );
        }

        self::assertNull(
            $this->executor->getExecutedCommand(0),
            'There should be no executed command for the version check',
        );
    }

    public function testAuditResolvesManagerSpecificBinaryConfiguration(): void
    {
        $managerName = $this->manager->getName();

        $customBinary = 'custom-manager';

        $this->config = new Config(
            [
                'manager-bin' => [$managerName => $customBinary],
                'run-asset-manager' => false,
            ],
        );

        $this->manager = $this->getManager();

        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . $this->manager->getLockPackageName(), '{}');

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        self::assertInstanceOf(
            AuditableAssetManagerInterface::class,
            $this->manager,
            'The manager should implement the AuditableAssetManagerInterface',
        );

        $this->manager->audit(false);

        [, $auditArguments] = explode(' ', $this->getValidAuditCommand(false), 2);

        self::assertSame(
            "{$customBinary} --version",
            $this->executor->getExecutedCommand(0),
            'The executed command for the version check should match the expected value',
        );
        self::assertSame(
            "{$customBinary} {$auditArguments}",
            $this->executor->getExecutedCommand(1),
            'The executed command for the audit should match the expected value',
        );
    }

    public function testAuditUsesConfiguredRootDirectoryWithoutChangingProcessDirectory(): void
    {
        $configuredRootPackageDir = 'root-package';
        $rootPackageDir = $this->cwd . DIRECTORY_SEPARATOR . $configuredRootPackageDir;

        $this->sfs->mkdir($rootPackageDir);

        $originalCwd = getcwd();

        $this->config = new Config(
            [
                'root-package-json-dir' => $configuredRootPackageDir,
                'run-asset-manager' => false,
            ],
        );

        $this->manager = $this->getManager();

        file_put_contents($rootPackageDir . DIRECTORY_SEPARATOR . $this->manager->getLockPackageName(), '{}');

        $this->executor->addExpectedValues(0, $this->getValidVersion());
        $this->executor->addExpectedValues(0, '{}');

        self::assertInstanceOf(
            AuditableAssetManagerInterface::class,
            $this->manager,
            'The manager should implement the AuditableAssetManagerInterface',
        );

        $this->manager->audit(false);

        self::assertSame(
            $rootPackageDir,
            $this->executor->getExecutedWorkingDirectory(0),
            'The executed working directory for the version check should match the expected value',
        );
        self::assertSame(
            $rootPackageDir,
            $this->executor->getExecutedWorkingDirectory(1),
            'The executed working directory for the audit should match the expected value',
        );
        self::assertSame(
            $originalCwd,
            getcwd(),
            'The current working directory should not have changed after the audit',
        );
    }
}
