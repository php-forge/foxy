<?php

declare(strict_types=1);

namespace Foxy\Audit;

/**
 * Represents one normalized frontend dependency security advisory.
 */
final readonly class AuditFinding
{
    /**
     * Creates an immutable audit finding.
     *
     * @param string $package Package affected by the advisory.
     * @param Severity $severity Normalized advisory severity.
     * @param string $advisoryId Preferred advisory identifier, such as a GHSA identifier.
     * @param string|null $sourceId Identifier reported by the package manager.
     * @param string $title Human-readable advisory title.
     * @param string $vulnerableVersions Version constraint reported as vulnerable.
     * @param string|null $url Advisory details URL.
     * @param list<string> $cves Associated CVE identifiers.
     * @param CveStatus $cveStatus Status of CVE resolution for this advisory.
     * @param list<string> $affectedVersions Installed versions affected by the advisory.
     * @param list<string> $dependencyPaths Dependency paths that include the vulnerable package.
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
     * Returns a copy with the supplied CVE resolution.
     *
     * @param list<string> $cves Resolved CVE identifiers.
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
