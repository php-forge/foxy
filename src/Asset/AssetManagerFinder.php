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

namespace Foxy\Asset;

use Foxy\Exception\RuntimeException;

/**
 * Asset Manager finder.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
final class AssetManagerFinder
{
    /**
     * @psalm-var AssetManagerInterface[]
     */
    private array $managers = [];

    /**
     * Constructor.
     *
     * @psalm-param AssetManagerInterface[] $managers The asset managers
     */
    public function __construct(array $managers = array())
    {
        foreach ($managers as $manager) {
            if ($manager instanceof AssetManagerInterface) {
                $this->addManager($manager);
            }
        }
    }

    public function addManager(AssetManagerInterface $manager): void
    {
        $this->managers[$manager->getName()] = $manager;
    }

    /**
     * Find the asset manager.
     *
     * @param null|string $manager The name of the asset manager
     *
     * @return AssetManagerInterface
     *
     * @throws RuntimeException When the asset manager does not exist
     * @throws RuntimeException When the asset manager is not found
     */
    public function findManager(string $manager = null): AssetManagerInterface
    {
        if (null !== $manager) {
            if (isset($this->managers[$manager])) {
                return $this->managers[$manager];
            }

            throw new RuntimeException(sprintf('The asset manager "%s" doesn\'t exist', $manager));
        }

        return $this->findAvailableManager();
    }

    /**
     * Find the available asset manager.
     *
     * @return AssetManagerInterface
     *
     * @throws RuntimeException When no asset manager is found
     */
    private function findAvailableManager(): AssetManagerInterface
    {
        // find asset manager by lockfile
        foreach ($this->managers as $manager) {
            if ($manager->hasLockFile()) {
                return $manager;
            }
        }

        // find asset manager by availability
        foreach ($this->managers as $manager) {
            if ($manager->isAvailable()) {
                return $manager;
            }
        }

        throw new RuntimeException('No asset manager is found');
    }
}
