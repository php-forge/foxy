<?php

declare(strict_types=1);

namespace Foxy\Audit;

/**
 * Describes the scope and failure threshold of an audit run.
 */
final readonly class AuditRequest
{
    /**
     * Creates an immutable audit request.
     *
     * @param Severity $minimumSeverity Lowest severity that makes the audit report vulnerable.
     * @param bool $noDev Whether development dependencies are excluded.
     */
    public function __construct(public Severity $minimumSeverity = Severity::LOW, public bool $noDev = false) {}
}
