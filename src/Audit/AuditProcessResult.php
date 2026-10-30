<?php

declare(strict_types=1);

namespace Foxy\Audit;

/**
 * Contains the raw result of a package manager audit process.
 */
final readonly class AuditProcessResult
{
    /**
     * Creates an immutable process result.
     *
     * @param int $exitCode Process exit status.
     * @param string $output Standard output captured from the process.
     * @param string $errorOutput Standard error captured from the process.
     */
    public function __construct(public int $exitCode, public string $output, public string $errorOutput) {}
}
