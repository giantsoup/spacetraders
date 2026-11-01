<?php

return [
    // Shared, private and outside release directories on a deployed installation.
    'path' => env('SPACETRADERS_DEPLOYMENT_PATH', env('SPACETRADERS_LOCK_PATH', storage_path('framework/game-locks')).'/deployment'),
    'release' => env('SPACETRADERS_RELEASE', 'local'),
];
