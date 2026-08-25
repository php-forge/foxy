<?php

declare(strict_types=1);

namespace Foxy\Audit;

use function array_unique;
use function count;

final readonly class AuditReport
{
    /**
     * @param list<AuditFinding> $findings
     */
    public function __construct(public string $manager, public array $findings, public string $diagnostics = '') {}

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
     * @param list<AuditFinding> $findings
     */
    public function withFindings(array $findings): self
    {
        return new self($this->manager, $findings, $this->diagnostics);
    }
}
