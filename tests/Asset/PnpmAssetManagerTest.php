<?php

declare(strict_types=1);

namespace Foxy\Tests\Asset;

use Composer\Util\ProcessExecutor;
use Foxy\Asset\PnpmManager;
use Foxy\Config\Config;

use function file_get_contents;
use function file_put_contents;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

final class PnpmAssetManagerTest extends AssetManager
{
    public function testAuditPreventsPnpmfileHooksFromMutatingTheWorkspace(): void
    {
        $probePath = $this->cwd . DIRECTORY_SEPARATOR . 'pnpm-probe.php';
        $workspacePath = $this->cwd . DIRECTORY_SEPARATOR . 'pnpm-workspace.yaml';
        $lockPath = $this->cwd . DIRECTORY_SEPARATOR . 'pnpm-lock.yaml';

        file_put_contents(
            $probePath,
            <<<'PHP'
                <?php

                declare(strict_types=1);

                if (in_array('--version', $argv, true)) {
                    echo '11.23.0';

                    exit(0);
                }

                if (!in_array('--ignore-pnpmfile', $argv, true)) {
                    file_put_contents('pnpm-workspace.yaml', "audit:\n  ignore: []\n");
                    file_put_contents('pnpm-lock.yaml', 'mutated');
                }

                echo '{}';
                PHP,
        );
        file_put_contents($this->cwd . DIRECTORY_SEPARATOR . '.pnpmfile.cjs', 'module.exports = {};');
        file_put_contents($lockPath, 'original');

        $this->config = new Config(
            [
                'manager-bin' => ProcessExecutor::escape(PHP_BINARY) . ' ' . ProcessExecutor::escape($probePath),
                'run-asset-manager' => false,
            ],
        );
        $manager = new PnpmManager(
            $this->io,
            $this->config,
            new ProcessExecutor($this->io),
            $this->fs,
            $this->fallback,
        );

        $result = $manager->audit(false);

        self::assertSame('{}', $result->output);
        self::assertSame('original', file_get_contents($lockPath));
        self::assertFileDoesNotExist($workspacePath);
    }

    protected function getManager(): PnpmManager
    {
        return new PnpmManager($this->io, $this->config, $this->executor, $this->fs, $this->fallback);
    }

    protected function getUnsupportedVersion(): string
    {
        return '11.22.0';
    }

    protected function getValidAuditCommand(bool $noDev): string
    {
        $command = 'pnpm audit --json --audit-level=info --lockfile-dir=. --ignore-pnpmfile --only=null';

        $command .= $noDev
            ? ' --prod --optional=true'
            : ' --prod=false --dev=false --optional=true';

        return $command
            . ' --ignore-registry-errors=false --ignore-unfixable=false '
            . ProcessExecutor::escape('--config.auditConfig={ignoreGhsas:[]}');
    }

    protected function getValidInstallCommand(): string
    {
        return 'pnpm install';
    }

    protected function getValidLockPackageName(): string
    {
        return 'pnpm-lock.yaml';
    }

    protected function getValidName(): string
    {
        return 'pnpm';
    }

    protected function getValidUpdateCommand(): string
    {
        return 'pnpm update';
    }

    protected function getValidVersion(): string
    {
        return '11.23.0';
    }

    protected function getValidVersionCommand(): string
    {
        return 'pnpm --version';
    }

    protected function getValidVersionConstraint(): string
    {
        return '^11.23.0';
    }
}
