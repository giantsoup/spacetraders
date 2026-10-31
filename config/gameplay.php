<?php

return [
    'fixture_path' => env('SPACETRADERS_FIXTURE_PATH'),
    'enabled' => env('SPACETRADERS_AUTOMATION_ENABLED', false),
    'lock_path' => env('SPACETRADERS_LOCK_PATH', storage_path('framework/game-locks')),
    'request_spacing_ms' => 650,
    'max_pages' => 10,
    'max_observations' => 24,
    'preview_seconds' => 120,
    'snapshot_seconds' => 180,
];
