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
        Filesystem $fs = null
    ) {
        $this->fs = $fs ?: new Filesystem();
    }

    public function save(): self
    {
        if (file_exists($this->path) && is_file($this->path)) {
            $this->originalContent = file_get_contents($this->path);
        }

        return $this;
    }

    public function restore(): void
    {
        if (!$this->config->get('fallback-asset')) {
            return;
        }

        $this->io->write('<info>Fallback to previous state for the Asset package</info>');
        $this->fs->remove($this->path);

        if (null !== $this->originalContent) {
            file_put_contents($this->path, $this->originalContent);
        }
    }
}
