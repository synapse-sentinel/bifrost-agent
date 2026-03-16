<?php

return [
    'default' => 'openrouter',

    'providers' => [
        'openrouter' => [
            'driver' => 'openrouter',
            'key'    => env('OPENROUTER_API_KEY'),
        ],
    ],
];
