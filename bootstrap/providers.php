<?php

use App\Providers\AppServiceProvider;
use Illuminate\Redis\RedisServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Prism\Prism\PrismServiceProvider;

return [
    AppServiceProvider::class,
    RedisServiceProvider::class,
    PrismServiceProvider::class,
    AiServiceProvider::class,
];
