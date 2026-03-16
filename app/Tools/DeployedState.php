<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeployedState implements Tool
{
    public function description(): Stringable|string
    {
        return 'Show what code is currently running in production: the deployed git SHA and recent commits on master. Call this before filing an issue or diagnosing a failure.';
    }

    public function handle(Request $request): Stringable|string
    {
        $deployed = trim($this->readDeployedSha());
        $recent = $this->recentCommits();

        if (! $recent) {
            return 'Deployed SHA: '.($deployed ?: 'unknown')."\n\nCould not fetch recent commits from GitHub.";
        }

        $masterSha = $recent[0]['sha'] ?? null;
        $lines = ['**Deployed SHA:** '.($deployed ?: 'unknown (pre-SHA-injection build)')];

        if ($deployed && $masterSha) {
            $status = str_starts_with($masterSha, $deployed) ? 'up to date' : "behind master ({$masterSha})";
            $lines[] = "**Status:** {$status}";
        }

        $lines[] = "\n**Recent commits on master:**";
        foreach ($recent as $commit) {
            $short = substr($commit['sha'], 0, 7);
            $message = strtok($commit['commit']['message'] ?? '', "\n");
            $lines[] = "- `{$short}` {$message}";
        }

        return implode("\n", $lines);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function readDeployedSha(): string
    {
        $path = config('services.bifrost.deploy_sha_path', '/opt/bifrost/.deploy_sha');

        return file_exists($path) ? (string) file_get_contents($path) : '';
    }

    protected function recentCommits(): ?array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->get('https://api.github.com/repos/jordanpartridge/bifrost/commits', ['per_page' => 5]);

        return $response->successful() ? $response->json() : null;
    }
}
