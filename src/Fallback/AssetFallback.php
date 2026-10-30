<?php

declare(strict_types=1);

namespace Foxy\Fallback;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Foxy\Config\Config;
use Foxy\Exception\{Message, RuntimeException};
use Throwable;

use function is_file;
use function is_link;

/**
 * Restores an asset manifest to the state captured before dependency solving.
 */
final class AssetFallback implements FallbackInterface
{
    private readonly Filesystem $fs;

    private string|null $originalContent = null;

    private bool $snapshotSaved = false;

    /**
     * Creates a fallback for one asset manifest path.
     *
     * @param IOInterface $io Composer I/O used to report restoration.
     * @param Config $config Foxy configuration controlling asset fallback.
     * @param string $path Asset manifest path to snapshot.
     * @param Filesystem|null $fs Filesystem implementation, or `null` to create the Composer default.
     */
    public function __construct(
        private readonly IOInterface $io,
        private readonly Config $config,
        private readonly string $path,
        Filesystem|null $fs = null,
    ) {
        $this->fs = $fs ?? new Filesystem();
    }

    /**
     * Restores the captured manifest, or removes it when Foxy created it.
     */
    public function restore(): void
    {
        if (!$this->isEnabled() || !$this->snapshotSaved) {
            return;
        }

        $this->io->write('<info>Fallback to previous state for the Asset package</info>');

        $this->assertPathIsFileIfExists();

        if (null !== $this->originalContent) {
            $this->writeOriginalContent($this->originalContent);

            return;
        }

        if ($this->pathExists()) {
            $this->removeCreatedManifest();
        }
    }

    /**
     * Captures the current manifest state when asset fallback is enabled.
     */
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
                    Message::FALLBACK_ASSET_READ_FAILED->getMessage($this->path),
                );
            }

            $this->originalContent = $content;
        }

        $this->snapshotSaved = true;

        return $this;
    }

    private function assertPathIsFileIfExists(): void
    {
        if ($this->pathExists() && !is_file($this->path)) {
            throw new RuntimeException(
                Message::FALLBACK_ASSET_PATH_NOT_FILE->getMessage($this->path),
            );
        }
    }

    private function isEnabled(): bool
    {
        return $this->config->isEnabled('fallback-asset');
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
                Message::FALLBACK_ASSET_REMOVE_FAILED->getMessage($this->path),
                0,
                $exception,
            );
        }

        if (true !== $removed) {
            throw new RuntimeException(
                Message::FALLBACK_ASSET_REMOVE_FAILED->getMessage($this->path),
            );
        }
    }

    private function resetSnapshot(): void
    {
        $this->originalContent = null;
        $this->snapshotSaved = false;
    }

    private function writeOriginalContent(string $content): void
    {
        try {
            $result = file_put_contents($this->path, $content);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                Message::FALLBACK_ASSET_WRITE_FAILED->getMessage($this->path),
                0,
                $exception,
            );
        }

        if (false === $result) {
            throw new RuntimeException(
                Message::FALLBACK_ASSET_WRITE_FAILED->getMessage($this->path),
            );
        }
    }
}
