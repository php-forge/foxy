<?php

declare(strict_types=1);

namespace Foxy\Audit;

enum AuditFormat: string
{
    case JSON = 'json';
    case PLAIN = 'plain';
    case SUMMARY = 'summary';
    case TABLE = 'table';
}
