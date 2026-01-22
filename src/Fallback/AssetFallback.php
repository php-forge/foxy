<?php

declare(strict_types=1);

/*
 * This file is part of the Foxy package.
 *
 * (c) François Pluchino <francois.pluchino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Foxy\Fallback;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Foxy\Config\Config;
use Foxy\Exception\RuntimeException;

/**
 * Asset fallback.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class AssetFallback implements FallbackInterface
{
    protected Filesystem $fs;
    protected string|null $originalContent = null;

    public function __construct(
        protected IOInterface $io,
        protected Config $config,
        protected string $path,
        Filesystem|null $fs = null
    ) {
        $this->fs = $fs ?: new Filesystem();
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

    public function restore(): void
    {
        if (!$this->config->get('fallback-asset')) {
            return;
        }

        $this->io->write('<info>Fallback to previous state for the Asset package</info>');

        try {
            $this->fs->remove($this->path);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                sprintf('Unable to remove fallback asset file "%s".', $this->path),
                0,
                $exception
            );
        }

        if (null !== $this->originalContent) {
            $result = file_put_contents($this->path, $this->originalContent);

            if (false === $result) {
                throw new RuntimeException(sprintf('Unable to write fallback asset file "%s".', $this->path));
            }
        }
    }
}
