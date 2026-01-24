<?php

declare(strict_types=1);

/** @var \Symplify\EasyCodingStandard\Configuration\ECSConfigBuilder $ecsConfigBuilder */
$ecsConfigBuilder = require __DIR__ . '/vendor/php-forge/coding-standard/config/ecs.php';

return $ecsConfigBuilder->withPaths(
    [
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ],
);
