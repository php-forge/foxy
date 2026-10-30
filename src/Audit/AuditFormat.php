<?php

declare(strict_types=1);

namespace Foxy\Audit;

/**
 * Output formats supported by the audit command.
 */
enum AuditFormat: string
{
    case JSON = 'json';
    case PLAIN = 'plain';
    case SUMMARY = 'summary';
    case TABLE = 'table';
}
