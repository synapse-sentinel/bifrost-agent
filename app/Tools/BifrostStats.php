<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class BifrostStats implements Tool
{
    public function description(): Stringable|string
    {
        return 'Check Bifrost system health and stats: Redis, queue depth, recent webhook traffic. Calls the Bifrost API.';
    }

    public function handle(Request $request): Stringable|string
    {
        $lines = [];

        // Redis
        try {
            Redis::ping();
            $queueDepth = Redis::llen('queues:bifrost') ?: 0;
            $lines[] = "Redis: OK | Queue depth: {$queueDepth}";
        } catch (\Throwable $e) {
            $lines[] = 'Redis: DOWN — '.$e->getMessage();
        }

        // Bifrost API health
        try {
            $url = config('services.bifrost.api_url');
            $response = Http::timeout(5)->get("{$url}/api/health");

            if ($response->successful()) {
                $data = $response->json();
                $db = $data['database'] ?? 'unknown';
                $lines[] = "Bifrost API: OK | DB: {$db}";
            } else {
                $lines[] = 'Bifrost API: '.$response->status();
            }
        } catch (\Throwable $e) {
            $lines[] = 'Bifrost API: unreachable — '.$e->getMessage();
        }

        return implode("\n", $lines);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
