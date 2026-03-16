<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class KnowledgeSearch implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search Jordan\'s knowledge base for information about projects, decisions, patterns, debugging insights, and architecture notes. Use this to answer questions about how things work, past decisions, or project context.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = $request['query'] ?? '';
        if (empty($query)) {
            return 'No search query provided.';
        }

        $project = $request['project'] ?? null;
        $limit = min((int) ($request['limit'] ?? 5), 10);

        $embedding = $this->embed($query);
        if ($embedding === null) {
            return 'Knowledge search unavailable: embeddings service unreachable.';
        }

        $collections = $project
            ? ["knowledge_{$project}"]
            : ['knowledge_bifrost', 'knowledge_default', 'knowledge_jordan'];

        $results = [];
        foreach ($collections as $collection) {
            $results = array_merge($results, $this->searchCollection($collection, $embedding, $limit));
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);
        $results = array_slice($results, 0, $limit);

        if (empty($results)) {
            return "No knowledge entries found for: {$query}";
        }

        $lines = array_map(function ($result) {
            $title = $result['title'] ?? 'Untitled';
            $category = $result['category'] ?? 'unknown';
            $score = round($result['score'], 2);
            $content = $result['content'] ?? '';
            $content = mb_strlen($content) > 300 ? mb_substr($content, 0, 300).'...' : $content;
            $tags = ! empty($result['tags']) ? implode(', ', $result['tags']) : '';

            $line = "[{$score}] {$title} ({$category})";
            if ($tags) {
                $line .= "\n  Tags: {$tags}";
            }
            $line .= "\n  {$content}";

            return $line;
        }, $results);

        return "Knowledge results for \"{$query}\" ({$limit} max):\n\n".implode("\n\n", $lines);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Natural language search query (e.g., "how does deploy work", "strava webhook setup", "Jordan preferences")')->required(),
            'project' => $schema->string()->description('Filter to a specific project namespace (e.g., "bifrost", "jordan", "odin"). Omit to search bifrost + default + jordan.'),
            'limit' => $schema->integer()->description('Max results to return (default 5, max 10)'),
        ];
    }

    private function embed(string $text): ?array
    {
        $host = config('services.knowledge.embeddings_host', 'host.containers.internal:8001');

        try {
            $response = Http::timeout(5)
                ->post("http://{$host}/embed", ['text' => $text]);

            if ($response->successful()) {
                $data = $response->json();

                return $data['embeddings'][0] ?? null;
            }
        } catch (\Throwable) {
            // Fall through
        }

        return null;
    }

    private function searchCollection(string $collection, array $embedding, int $limit): array
    {
        $host = config('services.knowledge.qdrant_host', 'host.containers.internal:6333');

        try {
            $response = Http::timeout(5)->post("http://{$host}/collections/{$collection}/points/search", [
                'vector' => $embedding,
                'limit' => $limit,
                'with_payload' => true,
                'score_threshold' => 0.3,
            ]);

            if (! $response->successful()) {
                return [];
            }

            return array_map(fn ($point) => [
                'score' => $point['score'] ?? 0,
                'title' => $point['payload']['title'] ?? '',
                'content' => $point['payload']['content'] ?? '',
                'category' => $point['payload']['category'] ?? '',
                'tags' => $point['payload']['tags'] ?? [],
            ], $response->json('result', []));
        } catch (\Throwable) {
            return [];
        }
    }
}
