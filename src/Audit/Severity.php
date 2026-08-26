<?php

declare(strict_types=1);

namespace Foxy\Audit;

enum Severity: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case INFO = 'info';
    case LOW = 'low';
    case MODERATE = 'moderate';

    public function isAtLeast(self $threshold): bool
    {
        return $this->weight() >= $threshold->weight();
    }

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
