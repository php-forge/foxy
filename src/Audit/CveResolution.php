<?php

declare(strict_types=1);

namespace Foxy\Audit;

final readonly class CveResolution
{
    /**
     * @param list<string> $cves
     */
    public function __construct(public array $cves, public CveStatus $status) {}
}
