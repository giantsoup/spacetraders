<?php

return [
    'local_engine_enabled' => env('SPACETRADERS_LOCAL_ENGINE_ENABLED', false),
    'operator_id' => env('SPACETRADERS_OPERATOR_ID'),
    'local_host' => env('SPACETRADERS_LOCAL_HOST', 'spacetraders.test'),
    'php_binary' => env('SPACETRADERS_PHP_BINARY') ?: PHP_BINARY,
    'investigation_requests' => 40,
    'investigation_seconds' => 45,
    'scheduling_allowance_seconds' => 120,
    // How long the supervisor waits for owned children to exit after End before it faults and
    // force stop becomes available. The CLI End waits this long plus 30 seconds.
    'engine_shutdown_seconds' => max(1, (int) env('SPACETRADERS_ENGINE_SHUTDOWN_SECONDS', 150)),
    'fixture_path' => env('SPACETRADERS_FIXTURE_PATH'),
    'enabled' => env('SPACETRADERS_AUTOMATION_ENABLED', false),
    'lock_path' => env('SPACETRADERS_LOCK_PATH', storage_path('framework/game-locks')),
    'request_budget_enabled' => ! env('SPACETRADERS_FIXTURE_PATH'),
    'request_budget_path' => (env('SPACETRADERS_REQUEST_BUDGET_PATH') ?: (env('SPACETRADERS_FIXTURE_PATH') ? dirname(env('SPACETRADERS_FIXTURE_PATH')).'/request-budget' : (PHP_OS_FAMILY === 'Windows'
        ? (getenv('LOCALAPPDATA') ?: sys_get_temp_dir()).'/SpaceTraders/request-budget'
        : (getenv('HOME') ?: sys_get_temp_dir()).'/Library/Application Support/SpaceTraders/request-budget'))),
    'max_pages' => 10,
    'max_observations' => 24,
    'manual_reserve_floor' => 2000,
    'preview_seconds' => 120,
    'snapshot_seconds' => 180,
    'fuel_quote_max_seconds' => 21600,
    'campaign_recovery_attempts' => 5,
    // An arm that positioned at its claimed source commits to that pair: it may buy against a buyer
    // quote up to this old (the sell floor stays the cost basis), and the commitment lasts at most
    // this many minutes after the ship arrives. Set the minutes to 0 to turn route commitment off.
    'campaign_commit_quote_max_age_seconds' => (int) env('SPACETRADERS_CAMPAIGN_COMMIT_QUOTE_MAX_AGE', 900),
    'campaign_commit_minutes' => (int) env('SPACETRADERS_CAMPAIGN_COMMIT_MINUTES', 10),
    'route_evidence' => env('SPACETRADERS_ROUTE_EVIDENCE', 'conservative'),
    'fuel_reserve_units' => 2,
    'opportunity_limit' => 120,
    'evidence_travel_limit' => 2000,
    'evidence_run_limit' => 500,
    'campaign_claims' => (bool) env('SPACETRADERS_CAMPAIGN_CLAIMS', true),
];
