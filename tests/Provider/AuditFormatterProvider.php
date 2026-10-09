<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use Foxy\Audit\CveStatus;

/**
 * Data provider for {@see \Foxy\Tests\Audit\AuditFormatterTest} test cases.
 */
final class AuditFormatterProvider
{
    /**
     * @return iterable<string, array{CveStatus, list<string>, string}>
     */
    public static function cveStatuses(): iterable
    {
        yield 'none assigned' => [CveStatus::NONE_ASSIGNED, [], 'None assigned'];
        yield 'not requested' => [CveStatus::NOT_REQUESTED, [], 'Not requested'];
        yield 'resolved' => [CveStatus::RESOLVED, ['CVE-2021-23337'], 'CVE-2021-23337'];
        yield 'unavailable' => [CveStatus::UNAVAILABLE, [], 'Unavailable'];
    }
}
