<?php

declare(strict_types=1);

namespace Foxy\Audit;

/**
 * Runs a package manager audit and returns normalized findings.
 */
interface AuditRunnerInterface
{
    /**
     * Audits the selected dependency set.
     */
    public function audit(AuditRequest $request): AuditReport;
}
