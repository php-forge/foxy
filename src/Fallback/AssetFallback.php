<?php

declare(strict_types=1);

namespace Foxy\Fallback;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Foxy\Config\Config;
use Foxy\Exception\RuntimeException;
use Throwable;

final class AssetFallback implements FallbackInterface
{
    private readonly Filesystem $fs;

    private string|null $originalContent = null;

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
        $fallbackAsset = $this->config->get('fallback-asset');

        if ($fallbackAsset !== true && $fallbackAsset !== 1 && $fallbackAsset !== '1') {
            return;
        }

        $this->io->write('<info>Fallback to previous state for the Asset package</info>');

        try {
            $this->fs->remove($this->path);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Unable to remove fallback asset file "%s".', $this->path),
                0,
                $exception,
            );
        }

        if (null !== $this->originalContent && $this->originalContent !== '') {
            $result = file_put_contents($this->path, $this->originalContent);

            if (false === $result) {
                throw new RuntimeException(sprintf('Unable to write fallback asset file "%s".', $this->path));
            }
        }
    }

    public function save(): self
    {
        if (file_exists($this->path) && is_file($this->path)) {
            $content = file_get_contents($this->path);

            if (false === $content) {
                throw new RuntimeException(sprintf('Unable to read fallback asset file "%s".', $this->path));
            }

            $this->originalContent = $content;
        }

        return $this;
    }
}
