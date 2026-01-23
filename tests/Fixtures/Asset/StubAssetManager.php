<?php

declare(strict_types=1);

namespace Foxy\Tests\Fixtures\Asset;

use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\{Filesystem, ProcessExecutor};
use Foxy\Asset\{AssetManagerInterface, AssetPackageInterface};
use Foxy\Config\Config;
use Foxy\Fallback\FallbackInterface;
use RuntimeException;

final class StubAssetManager implements AssetManagerInterface
{
    public function __construct(
        IOInterface $io,
        Config $config,
        ProcessExecutor $executor,
        Filesystem $fs,
    ) {}

    public function addDependencies(RootPackageInterface $rootPackage, array $dependencies): AssetPackageInterface
    {
        throw new RuntimeException('Not used in this test.');
    }

    public function getLockPackageName(): string
    {
        return 'stub-lock.json';
    }

    public function getName(): string
    {
        return 'stub';
    }

    public function getPackageName(): string
    {
        return 'stub-package.json';
    }

    public function hasLockFile(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isInstalled(): bool
    {
        return false;
    }

    public function isUpdatable(): bool
    {
        return false;
    }

    public function isValidForUpdate(): bool
    {
        return false;
    }

    public function run(): int
    {
        return 0;
    }

    public function setFallback(FallbackInterface $fallback): self
    {
        return $this;
    }

    public function setUpdatable(bool $updatable): self
    {
        return $this;
    }

    public function validate(): void {}
}
