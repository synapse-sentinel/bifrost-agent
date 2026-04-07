<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
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
        $url = config('services.bifrost.api_url');

        try {
            $response = Http::timeout(5)->get("{$url}/api/stats");

            if (! $response->successful()) {
                return 'Bifrost API: '.$response->status();
            }

            $data = $response->json();
        } catch (\Throwable $e) {
            return 'Bifrost API: unreachable — '.$e->getMessage();
        }

        $redis = $data['redis'] ?? 'unknown';
        $queueDepth = $data['queue_depth'] ?? 0;
        $webhooks24h = $data['webhooks_24h'] ?? 0;
        $sources = $data['sources'] ?? [];

        $lines = [];
        $lines[] = "Redis: {$redis} | Queue depth: {$queueDepth} | Webhooks (24h): {$webhooks24h}";

        if (! empty($sources)) {
            $lines[] = '';
            $lines[] = 'Per-source (24h):';
            foreach ($sources as $name => $info) {
                $count = $info['count_24h'] ?? 0;
                $last = $info['last_received'] ?? 'never';
                $lines[] = "  {$name}: {$count} webhooks | last: {$last}";
            }
        }

        return implode("\n", $lines);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
