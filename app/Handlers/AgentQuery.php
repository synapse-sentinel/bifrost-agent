<?php

namespace App\Handlers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use TheShit\Vector\Contracts\EmbeddingClient;
use TheShit\Vector\Qdrant;

/**
 * Handles agent-to-agent queries over Redis.
 *
 * Incoming event format:
 * {
 *   "action": "stats" | "search" | "sources",
 *   "query": "optional search query",
 *   "project": "optional project filter",
 *   "limit": 5,
 *   "reply_channel": "lexi.bifrost.response",
 *   "request_id": "uuid"
 * }
 *
 * Responds with JSON on the reply_channel. No personality, just data.
 */
class AgentQuery
{
    public function handle(array $event): void
    {
        $action = $event['action'] ?? '';
        $replyChannel = $event['reply_channel'] ?? null;
        $requestId = $event['request_id'] ?? null;

        if (! $replyChannel) {
            Log::warning('AgentQuery: no reply_channel specified');

            return;
        }

        $result = match ($action) {
            'stats' => $this->stats(),
            'search' => $this->search($event),
            'sources' => $this->sources(),
            default => ['error' => "Unknown action: {$action}"],
        };

        $response = [
            'request_id' => $requestId,
            'action' => $action,
            'data' => $result,
        ];

        try {
            Redis::publish($replyChannel, json_encode($response));
        } catch (\Throwable $e) {
            Log::error('AgentQuery: failed to publish response', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $url = config('services.bifrost.api_url');

        try {
            $response = Http::timeout(5)->get("{$url}/api/stats");

            if (! $response->successful()) {
                return ['error' => 'Bifrost API returned '.$response->status()];
            }

            return $response->json();
        } catch (\Throwable $e) {
            return ['error' => 'Bifrost API unreachable: '.$e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function search(array $event): array
    {
        $query = $event['query'] ?? '';
        if ($query === '') {
            return ['error' => 'No query provided'];
        }

        $project = $event['project'] ?? null;
        $limit = min((int) ($event['limit'] ?? 5), 10);

        /** @var EmbeddingClient $embeddings */
        $embeddings = app(EmbeddingClient::class);
        $vector = $embeddings->embed($query);

        if ($vector === []) {
            return ['error' => 'Embedding service unavailable'];
        }

        /** @var Qdrant $qdrant */
        $qdrant = app(Qdrant::class);

        $collections = $project
            ? ["knowledge_{$project}"]
            : ['knowledge_bifrost', 'knowledge_default', 'knowledge_jordan'];

        $results = [];
        foreach ($collections as $collection) {
            try {
                $points = $qdrant->search($collection, $vector, $limit, null, 0.3);
                foreach ($points as $point) {
                    $results[] = [
                        'id' => $point->id,
                        'score' => $point->score,
                        'title' => $point->payload['title'] ?? '',
                        'content' => $point->payload['content'] ?? '',
                        'category' => $point->payload['category'] ?? '',
                        'tags' => $point->payload['tags'] ?? [],
                        'collection' => $collection,
                    ];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'query' => $query,
            'results' => array_slice($results, 0, $limit),
            'total' => count($results),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sources(): array
    {
        $url = config('services.bifrost.api_url');

        try {
            $response = Http::timeout(5)->get("{$url}/api/stats");

            if (! $response->successful()) {
                return ['error' => 'Bifrost API returned '.$response->status()];
            }

            return [
                'sources' => $response->json('sources', []),
                'webhooks_24h' => $response->json('webhooks_24h', 0),
            ];
        } catch (\Throwable $e) {
            return ['error' => 'Bifrost API unreachable: '.$e->getMessage()];
        }
    }
}
