<?php

declare(strict_types=1);

namespace Foxy\Audit;

final readonly class AuditProcessResult
{
    public function __construct(public int $exitCode, public string $output, public string $errorOutput) {}
}
