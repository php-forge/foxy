<?php

declare(strict_types=1);

namespace Foxy\Audit;

interface CveResolverInterface
{
    public function resolve(string $ghsaId): CveResolution;
}
