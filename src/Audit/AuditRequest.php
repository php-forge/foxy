<?php

declare(strict_types=1);

namespace Foxy\Audit;

final readonly class AuditRequest
{
    public function __construct(public Severity $minimumSeverity = Severity::LOW, public bool $noDev = false) {}
}
