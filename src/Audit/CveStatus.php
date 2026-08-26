<?php

declare(strict_types=1);

namespace Foxy\Audit;

enum CveStatus: string
{
    case NONE_ASSIGNED = 'none_assigned';
    case NOT_REQUESTED = 'not_requested';
    case RESOLVED = 'resolved';
    case UNAVAILABLE = 'unavailable';
}
