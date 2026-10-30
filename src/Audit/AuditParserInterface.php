<?php

declare(strict_types=1);

namespace Foxy\Audit;

/**
 * Parses a package manager's audit output into normalized findings.
 */
interface AuditParserInterface
{
    /**
     * Parses the complete audit command output.
     *
     * @return list<AuditFinding>
     */
    public function parse(string $output): array;
}
