<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Foxy\Audit\{AuditProcessResult, AuditableAssetManagerInterface};
use Foxy\Exception\{Message, RuntimeException};

use function array_key_exists;
use function getenv;
use function putenv;

abstract class AbstractAuditableAssetManager extends AbstractAssetManager implements AuditableAssetManagerInterface
{
    /**
     * Get the command to audit the asset dependencies.
     */
    abstract protected function getAuditCommand(bool $noDev): string;

    public function audit(bool $noDev): AuditProcessResult
    {
        if (!$this->hasLockFile()) {
            throw new RuntimeException(
                Message::ASSET_MANAGER_LOCK_FILE_MISSING->getMessage($this->getName(), $this->getLockFilePath()),
            );
        }

        $this->validateAuditConfiguration($noDev);
        $this->validate();

        return $this->withManagerTimeout(
            function () use ($noDev): AuditProcessResult {
                $environment = $this->overrideEnvironment($this->getAuditEnvironment());

                try {
                    $output = '';
                    $result = $this->executor->execute(
                        $this->getAuditCommand($noDev),
                        $output,
                        $this->getManagerWorkingDirectory(),
                    );

                    return new AuditProcessResult($result, (string) $output, $this->executor->getErrorOutput());
                } finally {
                    $this->restoreEnvironment($environment);
                }
            },
        );
    }

    /**
     * Get environment overrides required for a complete audit.
     *
     * @return array<string, string>
     */
    protected function getAuditEnvironment(): array
    {
        return [];
    }

    /**
     * Validate manager configuration that can change the audit scope.
     */
    protected function validateAuditConfiguration(bool $noDev): void {}

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, array{
     *     process: string|false,
     *     envExists: bool,
     *     env: mixed,
     *     serverExists: bool,
     *     server: mixed,
     * }>
     */
    private function overrideEnvironment(array $environment): array
    {
        $state = [];

        foreach ($environment as $name => $value) {
            $state[$name] = [
                'process' => getenv($name),
                'envExists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'serverExists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
            ];

            putenv("{$name}={$value}");

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
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
    private function restoreEnvironment(array $state): void
    {
        foreach ($state as $name => $values) {
            putenv(false === $values['process'] ? $name : "{$name}=" . $values['process']);

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
    }
}
