<?php

declare(strict_types=1);

namespace Foxy\Audit;

final readonly class AuditFinding
{
    /**
     * @param list<string> $cves
     * @param list<string> $affectedVersions
     * @param list<string> $dependencyPaths
     */
    public function __construct(
        public string $package,
        public Severity $severity,
        public string $advisoryId,
        public string|null $sourceId,
        public string $title,
        public string $vulnerableVersions,
        public string|null $url = null,
        public array $cves = [],
        public CveStatus $cveStatus = CveStatus::NOT_REQUESTED,
        public array $affectedVersions = [],
        public array $dependencyPaths = [],
    ) {}

    /**
     * @param list<string> $cves
     */
    public function withCveResolution(array $cves, CveStatus $status): self
    {
        return new self(
            $this->package,
            $this->severity,
            $this->advisoryId,
            $this->sourceId,
            $this->title,
            $this->vulnerableVersions,
            $this->url,
            $cves,
            $status,
            $this->affectedVersions,
            $this->dependencyPaths,
        );
    }
}
