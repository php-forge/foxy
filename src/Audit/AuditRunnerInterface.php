<?php

declare(strict_types=1);

namespace Foxy\Audit;

interface AuditRunnerInterface
{
    public function audit(AuditRequest $request): AuditReport;
}
