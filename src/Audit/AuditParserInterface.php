<?php

declare(strict_types=1);

namespace Foxy\Audit;

interface AuditParserInterface
{
    /**
     * @return list<AuditFinding>
     */
    public function parse(string $output): array;
}
