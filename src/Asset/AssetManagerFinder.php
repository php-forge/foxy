<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Foxy\Exception\RuntimeException;

use function sprintf;

final class AssetManagerFinder
{
    /**
     * @psalm-var AssetManagerInterface[]
     */
    private array $managers = [];

    /**
     * @psalm-param AssetManagerInterface[] $managers The asset managers
     */
    public function __construct(array $managers = [])
    {
        foreach ($managers as $manager) {
            $this->addManager($manager);
        }
    }

    public function addManager(AssetManagerInterface $manager): void
    {
        $this->managers[$manager->getName()] = $manager;
    }

    /**
     * Find the asset manager.
     *
     * @param string|null $manager The name of the asset manager
     *
     * @throws RuntimeException When the asset manager does not exist
     * @throws RuntimeException When the asset manager is not found
     */
    public function findManager(string|null $manager = null): AssetManagerInterface
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
