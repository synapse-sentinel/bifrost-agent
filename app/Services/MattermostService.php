<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MattermostService
{
    public function createPost(string $channelId, string $text, ?string $rootId = null): ?string
    {
        $payload = ['channel_id' => $channelId, 'message' => $text];

        if ($rootId) {
            $payload['root_id'] = $rootId;
        }

        try {
            $response = Http::withToken($this->token())
                ->timeout(10)
                ->post($this->url('/api/v4/posts'), $payload);

            if ($response->successful()) {
                return $response->json('id');
            }

            Log::warning('MattermostService: post failed', ['status' => $response->status()]);
        } catch (Throwable $e) {
            Log::error('MattermostService: HTTP error', ['error' => $e->getMessage()]);
        }

        return null;
    }

    public function sendTyping(string $channelId): void
    {
        try {
            Http::withToken($this->token())
                ->timeout(5)
                ->post($this->url('/api/v4/users/me/typing'), ['channel_id' => $channelId]);
        } catch (Throwable) {
        }
    }

    public function getMe(): ?array
    {
        try {
            $response = Http::withToken($this->token())
                ->timeout(10)
                ->get($this->url('/api/v4/users/me'));

            if ($response->successful()) {
                return $response->json();
            }
        } catch (Throwable $e) {
            Log::error('MattermostService: getMe failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function token(): string
    {
        return (string) config('services.mattermost.bot_token', '');
    }

    private function url(string $path): string
    {
        return config('services.mattermost.url', 'http://localhost:8065').$path;
    }
}
