<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php74\Rector\If_\IfToNullCoalescingAssignRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->import(__DIR__ . '/vendor/php-forge/coding-standard/src/rector-83.php');

    $rectorConfig->paths(
        [
            __DIR__ . '/src',
            __DIR__ . '/tests',
        ],
    );

    // `AuditCommand::sanitize()` keeps its explicit `null` check: the `??=` form yields a mutant Infection cannot kill.
    $rectorConfig->skip(
        [
            IfToNullCoalescingAssignRector::class => [__DIR__ . '/src/Command/AuditCommand.php'],
        ],
    );
};
