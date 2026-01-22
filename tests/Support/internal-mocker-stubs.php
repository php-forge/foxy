<?php

declare(strict_types=1);

/**
 * Overrides internal-mocker stubs for specific functions.
 */

$stubs = require __DIR__ . '/../../vendor/xepozz/internal-mocker/src/stubs.php';

$stubs['file_get_contents'] = [
    'signatureArguments' => 'string $filename, bool $use_include_path = false, $context = null, int $offset = 0, int|null $length = null',
    'arguments' => '$filename, $use_include_path, $context, $offset, $length',
];

$stubs['file_put_contents'] = [
    'signatureArguments' => 'string $filename, mixed $data, int $flags = 0, $context = null',
    'arguments' => '$filename, $data, $flags, $context',
];

return $stubs;
