<?php

return [
    'local_engine_enabled' => env('SPACETRADERS_LOCAL_ENGINE_ENABLED', false),
    'operator_id' => env('SPACETRADERS_OPERATOR_ID'),
    'local_host' => env('SPACETRADERS_LOCAL_HOST', 'spacetraders.test'),
    'php_binary' => env('SPACETRADERS_PHP_BINARY') ?: PHP_BINARY,
    'investigation_requests' => 40,
    'investigation_seconds' => 45,
    'scheduling_allowance_seconds' => 120,
    'fixture_path' => env('SPACETRADERS_FIXTURE_PATH'),
    'enabled' => env('SPACETRADERS_AUTOMATION_ENABLED', false),
    'lock_path' => env('SPACETRADERS_LOCK_PATH', storage_path('framework/game-locks')),
    'request_spacing_ms' => env('SPACETRADERS_FIXTURE_PATH') ? 0 : 650,
    'max_pages' => 10,
    'max_observations' => 24,
    'preview_seconds' => 120,
    'snapshot_seconds' => 180,
];
