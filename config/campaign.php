<?php

return [
    'alert_webhook_url' => env('CAMPAIGN_ALERT_WEBHOOK_URL'),
    'alert_mention_user_id' => env('CAMPAIGN_ALERT_MENTION_USER_ID'),
    'alert_dashboard_url' => env('CAMPAIGN_ALERT_DASHBOARD_URL'),
    'watchdog_state_path' => storage_path('app/campaign-watchdog.json'),
    'watchdog_log_path' => storage_path('logs/campaign-watchdog.log'),
];
