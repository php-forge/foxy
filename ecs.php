<?php

declare(strict_types=1);

/** @var \Symplify\EasyCodingStandard\Configuration\ECSConfigBuilder $ecsConfigBuilder */
$ecsConfigBuilder = require __DIR__ . '/vendor/php-forge/coding-standard/src/ecs-83.php';

return $ecsConfigBuilder->withPaths(
    [
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ],
);
