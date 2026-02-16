<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Discord Webhook Settings
    |--------------------------------------------------------------------------
    |
    | Settings for Discord webhook notifications.
    |
    */

    'webhooks' => [
        'panic_mode' => [
            'url' => env('DISCORD_PANIC_WEBHOOK_URL', ''),
            'enabled' => env('DISCORD_PANIC_WEBHOOK_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Panic Mode Settings
    |--------------------------------------------------------------------------
    |
    | Settings for the panic mode feature.
    |
    */

    'panic_mode' => [
        'bandwidth_threshold_mbps' => env('PANIC_MODE_BANDWIDTH_THRESHOLD_MBPS', 1),
        'cooldown_minutes' => env('PANIC_MODE_COOLDOWN_MINUTES', 15),
    ],
];
