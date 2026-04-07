<?php

use Monolog\Handler\StreamHandler;

return [
    'default' => env('LOG_CHANNEL', 'stderr'),

    'channels' => [
        'stderr' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/bifrost-agent.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],
    ],
];
