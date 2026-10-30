<?php

declare(strict_types=1);

namespace Foxy\Audit;

/**
 * Contains the CVE identifiers resolved for one security advisory.
 */
final readonly class CveResolution
{
    /**
     * Creates an immutable CVE resolution.
     *
     * @param list<string> $cves Resolved CVE identifiers.
     * @param CveStatus $status Outcome of the CVE lookup.
     */
    public function __construct(public array $cves, public CveStatus $status) {}
}
