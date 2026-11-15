<?php

return [
    'alert_webhook_url' => env('CAMPAIGN_ALERT_WEBHOOK_URL'),
    'watchdog_state_path' => storage_path('app/campaign-watchdog.json'),
    'watchdog_log_path' => storage_path('logs/campaign-watchdog.log'),
];
