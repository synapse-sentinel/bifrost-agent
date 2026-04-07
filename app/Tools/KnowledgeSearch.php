<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use TheShit\Vector\Contracts\EmbeddingClient;
use TheShit\Vector\Qdrant;

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

        /** @var EmbeddingClient $embeddings */
        $embeddings = app(EmbeddingClient::class);
        $vector = $embeddings->embed($query);

        if ($vector === []) {
            return 'Knowledge search unavailable: embeddings service unreachable.';
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
                        'score' => $point->score,
                        'title' => $point->payload['title'] ?? '',
                        'content' => $point->payload['content'] ?? '',
                        'category' => $point->payload['category'] ?? '',
                        'tags' => $point->payload['tags'] ?? [],
                    ];
                }
            } catch (\Throwable) {
                continue;
            }
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
}
