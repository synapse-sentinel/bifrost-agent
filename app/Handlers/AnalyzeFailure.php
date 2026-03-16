<?php

namespace App\Handlers;

use App\Agent\BifrostAgent;
use Illuminate\Support\Facades\Log;

class AnalyzeFailure
{
    public function handle(array $event): void
    {
        $payload = $event['payload'] ?? [];
        $job = $payload['job'] ?? 'Unknown job';
        $exception = $payload['exception'] ?? 'Unknown error';
        $queue = $payload['queue'] ?? 'default';
        $failedAt = $payload['failed_at'] ?? now()->toIso8601String();

        Log::info('AnalyzeFailure: received', ['job' => $job]);

        $prompt = $this->buildPrompt($job, $exception, $queue, $failedAt);

        try {
            $result = (string) BifrostAgent::make()->prompt($prompt);
            Log::info('AnalyzeFailure: agent completed', ['job' => $job, 'result' => substr($result, 0, 200)]);
        } catch (\Throwable $e) {
            Log::error('AnalyzeFailure: agent failed', ['job' => $job, 'error' => $e->getMessage()]);
        }
    }

    private function buildPrompt(string $job, string $exception, string $queue, string $failedAt): string
    {
        $shortClass = class_basename(str_replace('\\', '/', $job));

        return <<<PROMPT
        A queued job just failed in production. Your job is to analyze it and file a rich GitHub issue.

        **Failed job:** `{$job}`
        **Exception:** `{$exception}`
        **Queue:** {$queue}
        **Failed at:** {$failedAt}

        Steps:
        1. Call DeployedState to confirm what's actually running.
        2. Call ReadSourceFile with the path for `{$shortClass}.php` (look in Listeners/, Jobs/, etc.).
        3. Call KnowledgeSearch for "{$shortClass} failure" to check if this has happened before.
        4. Call GitHubIssueCreate with a rich issue that includes:
           - The exact exception and which line it likely hit (based on the source you read)
           - A relevant code snippet showing the failure point
           - Recent context from deployed state
           - Any knowledge-base matches
           - A suggested fix if the cause is clear
           - Labels: bug

        Do not ask for more information — use your tools to find it.
        PROMPT;
    }
}
