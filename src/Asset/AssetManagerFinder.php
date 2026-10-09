<?php

declare(strict_types=1);

namespace Foxy\Asset;

use Foxy\Exception\{Message, RuntimeException};

use function count;

final class AssetManagerFinder
{
    /**
     * @var AssetManagerInterface[]
     */
    private array $managers = [];

    /**
     * @param AssetManagerInterface[] $managers The asset managers
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
     * @param bool $checkAvailability Whether to check automatically selected manager availability
     *
     * @throws RuntimeException When the asset manager does not exist
     * @throws RuntimeException When the asset manager is not found
     */
    public function findManager(string|null $manager = null, bool $checkAvailability = true): AssetManagerInterface
    {
        if (null !== $manager) {
            if (isset($this->managers[$manager])) {
                return $this->managers[$manager];
            }

            throw new RuntimeException(
                Message::ASSET_MANAGER_UNKNOWN->getMessage($manager),
            );
        }

        return $this->findAvailableManager($checkAvailability);
    }

    /**
     * Find the available asset manager.
     *
     * @throws RuntimeException When no asset manager is found
     */
    private function findAvailableManager(bool $checkAvailability): AssetManagerInterface
    {
        $lockedManagers = [];

        // Find asset managers by their native lockfile first.
        foreach ($this->managers as $manager) {
            if ($manager->hasLockFile()) {
                $lockedManagers[] = $manager;
            }
        }

        if (count($lockedManagers) > 1) {
            throw new RuntimeException(
                Message::ASSET_MANAGER_LOCK_FILES_AMBIGUOUS->getMessage(),
            );
        }

        if (isset($lockedManagers[0])) {
            if (!$checkAvailability || $lockedManagers[0]->isAvailable()) {
                return $lockedManagers[0];
            }

            throw new RuntimeException(
                Message::ASSET_MANAGER_LOCKED_UNAVAILABLE->getMessage($lockedManagers[0]->getName()),
            );
        }

        // Find the first manager when no lockfile exists, probing it only when requested.
        foreach ($this->managers as $manager) {
            if (!$checkAvailability || $manager->isAvailable()) {
                return $manager;
            }
        }

        throw new RuntimeException(
            Message::ASSET_MANAGER_NONE_FOUND->getMessage(),
        );
    }
}
