<?php

declare(strict_types=1);

namespace Foxy\Solver;

use Composer\Composer;
use Composer\IO\IOInterface;

interface SolverInterface
{
    /**
     * Define if the update action can be used.
     *
     * @param bool $updatable The value of updatable.
     */
    public function setUpdatable(bool $updatable): self;

    /**
     * Solve the asset dependencies.
     *
     * @param Composer $composer The composer instance.
     * @param IOInterface $io The IO instance.
     */
    public function solve(Composer $composer, IOInterface $io): void;
}
