<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GitHubIssueCreate implements Tool
{
    public function description(): Stringable|string
    {
        return 'Create a GitHub issue to track a bug, feature request, or task. Use this when Jordan asks to track something, file an issue, or when a recurring error or pattern emerges in conversation that should be documented as a GitHub issue.';
    }

    public function handle(Request $request): Stringable|string
    {
        $title = $request['title'] ?? '';
        $body = $request['body'] ?? '';
        $repo = $request['repo'] ?? 'jordanpartridge/bifrost';
        $labels = $request['labels'] ?? '';

        if (empty($title) || empty($body)) {
            return 'Cannot create issue: title and body are required.';
        }

        $token = $this->installationToken();

        if (! $token) {
            return 'GitHub issue creation unavailable: could not obtain installation token.';
        }

        $payload = ['title' => $title, 'body' => $body];

        if (! empty($labels)) {
            $payload['labels'] = array_map('trim', explode(',', $labels));
        }

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->post("https://api.github.com/repos/{$repo}/issues", $payload);

        if (! $response->successful()) {
            return 'Failed to create issue: '.$response->json('message', 'unknown error');
        }

        return 'Issue created: '.$response->json('html_url');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Issue title — concise summary of the bug, feature, or task')->required(),
            'body' => $schema->string()->description('Issue body in markdown — include context, steps to reproduce, or acceptance criteria')->required(),
            'repo' => $schema->string()->description('Target repository in owner/repo format (default: jordanpartridge/bifrost)'),
            'labels' => $schema->string()->description('Comma-separated label names to apply (e.g., "bug,priority:high")'),
        ];
    }

    protected function installationToken(): ?string
    {
        $appId = config('services.github_app.app_id');
        $installationId = config('services.github_app.installation_id');
        $privateKeyBase64 = config('services.github_app.private_key_base64');

        if (! $appId || ! $installationId || ! $privateKeyBase64) {
            return null;
        }

        $jwt = $this->generateJwt($appId, base64_decode($privateKeyBase64));

        if (! $jwt) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$jwt}",
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->post("https://api.github.com/app/installations/{$installationId}/access_tokens");

        return $response->successful() ? $response->json('token') : null;
    }

    protected function generateJwt(string $appId, string $privateKey): ?string
    {
        $now = time();

        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => $appId]));
        $unsigned = "{$header}.{$payload}";

        $key = openssl_pkey_get_private($privateKey);

        if (! $key) {
            return null;
        }

        $signature = '';
        if (! $this->opensslSign($unsigned, $signature, $key)) {
            return null;
        }

        return "{$unsigned}.".$this->base64UrlEncode($signature);
    }

    protected function opensslSign(string $data, string &$signature, mixed $key): bool
    {
        return (bool) openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
