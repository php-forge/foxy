<?php

declare(strict_types=1);

namespace Foxy\Audit;

use function array_unique;
use function count;

/**
 * Contains normalized findings and diagnostics from one package manager audit.
 */
final readonly class AuditReport
{
    /**
     * Creates an immutable audit report.
     *
     * @param string $manager Package manager that produced the report.
     * @param list<AuditFinding> $findings Normalized security findings.
     * @param string $diagnostics Diagnostic output emitted by the package manager.
     */
    public function __construct(public string $manager, public array $findings, public string $diagnostics = '') {}

    /**
     * Returns the number of distinct affected packages.
     */
    public function countPackages(): int
    {
        return count(
            array_unique(
                array_map(
                    static fn(AuditFinding $finding): string => $finding->package,
                    $this->findings,
                ),
            ),
        );
    }

    /**
     * Counts findings for every supported severity.
     *
     * @return array<string, int>
     */
    public function countSeverities(): array
    {
        $counts = [
            Severity::CRITICAL->value => 0,
            Severity::HIGH->value => 0,
            Severity::MODERATE->value => 0,
            Severity::LOW->value => 0,
            Severity::INFO->value => 0,
        ];

        foreach ($this->findings as $finding) {
            ++$counts[$finding->severity->value];
        }

        return $counts;
    }

    /**
     * Whether any finding meets or exceeds the minimum severity.
     */
    public function hasFindingAtLeast(Severity $minimumSeverity): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity->isAtLeast($minimumSeverity)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns a copy with the supplied findings.
     *
     * @param list<AuditFinding> $findings Replacement findings.
     */
    public function withFindings(array $findings): self
    {
        return new self($this->manager, $findings, $this->diagnostics);
    }
}
