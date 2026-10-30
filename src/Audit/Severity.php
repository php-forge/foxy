<?php

declare(strict_types=1);

namespace Foxy\Audit;

/**
 * Normalized security advisory severity levels.
 */
enum Severity: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case INFO = 'info';
    case LOW = 'low';
    case MODERATE = 'moderate';

    /**
     * Whether this severity meets or exceeds the threshold.
     */
    public function isAtLeast(self $threshold): bool
    {
        return $this->weight() >= $threshold->weight();
    }

    /**
     * Returns the relative ordering weight of this severity.
     */
    public function weight(): int
    {
        return match ($this) {
            self::INFO => 0,
            self::LOW => 1,
            self::MODERATE => 2,
            self::HIGH => 3,
            self::CRITICAL => 4,
        };
    }
}
