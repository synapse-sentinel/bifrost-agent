<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class KnowledgeStore implements Tool
{
    public function description(): Stringable|string
    {
        return 'Store a notable insight, pattern, decision, or observation into long-term memory. Use this after conversations where something worth remembering emerges — a recurring issue, a user preference, an architectural decision, a pattern in the data. Tag accurately so recall is precise later.';
    }

    public function handle(Request $request): Stringable|string
    {
        $title = $request['title'] ?? '';
        $content = $request['content'] ?? '';
        $category = $request['category'] ?? 'architecture';
        $tags = $request['tags'] ?? '';
        $priority = $request['priority'] ?? 'medium';

        if (empty($title) || empty($content)) {
            return 'Cannot store: title and content are required.';
        }

        $know = $this->knowPath();

        if (! $know) {
            return 'Knowledge store unavailable: know CLI not found.';
        }

        $result = Process::run([
            $know, 'add', $title,
            '--content', $content,
            '--category', $category,
            '--tags', $tags,
            '--priority', $priority,
            '--confidence', '70',
        ]);

        if ($result->failed()) {
            return 'Failed to store knowledge: '.$result->errorOutput();
        }

        return "Stored: \"{$title}\" [{$category}]";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Short summary of what to remember (5-100 chars)')->required(),
            'content' => $schema->string()->description('Detailed description of the insight, pattern, or decision')->required(),
            'category' => $schema->string()->description('One of: architecture, patterns, decisions, gotchas, debugging, testing, deployment, security'),
            'tags' => $schema->string()->description('Comma-separated tags (e.g., "slack,webhook,bifrost,jordan")'),
            'priority' => $schema->string()->description('One of: critical, high, medium, low'),
        ];
    }

    protected function knowPath(): ?string
    {
        foreach ($this->knowCandidates() as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function knowCandidates(): array
    {
        return [
            '/home/jordan/.config/composer/vendor/bin/know',
            '/usr/local/bin/know',
        ];
    }
}
