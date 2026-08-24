<?php

declare(strict_types=1);

namespace Foxy\Fallback;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Foxy\Config\Config;
use Foxy\Exception\RuntimeException;
use Throwable;

use function is_file;
use function is_link;
use function sprintf;

final class AssetFallback implements FallbackInterface
{
    private readonly Filesystem $fs;

    private string $originalContent = '';

    private bool $originalExisted = false;

    private bool $snapshotSaved = false;

    public function __construct(
        private readonly IOInterface $io,
        private readonly Config $config,
        private readonly string $path,
        Filesystem|null $fs = null,
    ) {
        $this->fs = $fs ?? new Filesystem();
    }

    public function restore(): void
    {
        if (!$this->isEnabled() || !$this->snapshotSaved) {
            return;
        }

        $this->io->write('<info>Fallback to previous state for the Asset package</info>');

        $this->assertPathIsFileIfExists();

        if ($this->originalExisted) {
            $this->writeOriginalContent();

            return;
        }

        if ($this->pathExists()) {
            $this->removeCreatedManifest();
        }
    }

    public function save(): self
    {
        $this->resetSnapshot();

        if (!$this->isEnabled()) {
            return $this;
        }

        $this->assertPathIsFileIfExists();

        if ($this->pathExists()) {
            $content = file_get_contents($this->path);

            if (false === $content) {
                throw new RuntimeException(
                    sprintf('Unable to read fallback asset file "%s".', $this->path),
                );
            }

            $this->originalContent = $content;
            $this->originalExisted = true;
        }

        $this->snapshotSaved = true;

        return $this;
    }

    private function assertPathIsFileIfExists(): void
    {
        if ($this->pathExists() && !is_file($this->path)) {
            throw new RuntimeException(
                sprintf('The fallback asset path "%s" must be a regular file.', $this->path),
            );
        }
    }

    private function isEnabled(): bool
    {
        $fallbackAsset = $this->config->get('fallback-asset');

        return $fallbackAsset === true || $fallbackAsset === 1 || $fallbackAsset === '1';
    }

    private function pathExists(): bool
    {
        return file_exists($this->path) || is_link($this->path);
    }

    private function removeCreatedManifest(): void
    {
        try {
            $removed = $this->fs->remove($this->path);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Unable to remove fallback asset file "%s".', $this->path),
                0,
                $exception,
            );
        }

        if (true !== $removed) {
            throw new RuntimeException(
                sprintf('Unable to remove fallback asset file "%s".', $this->path),
            );
        }
    }

    private function resetSnapshot(): void
    {
        $this->originalContent = '';
        $this->originalExisted = false;
        $this->snapshotSaved = false;
    }

    private function writeOriginalContent(): void
    {
        try {
            $result = file_put_contents($this->path, $this->originalContent);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Unable to write fallback asset file "%s".', $this->path),
                0,
                $exception,
            );
        }

        if (false === $result) {
            throw new RuntimeException(
                sprintf('Unable to write fallback asset file "%s".', $this->path),
            );
        }
    }
}
