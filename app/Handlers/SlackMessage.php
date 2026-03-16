<?php

namespace App\Handlers;

use App\Agent\BifrostAgent;
use App\Support\ConversationMemory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackMessage
{
    public function handle(array $event): void
    {
        $payload = $event['payload'] ?? [];
        $slackEvent = $payload['event'] ?? [];

        if (! $this->shouldRespond($slackEvent)) {
            return;
        }

        $text = $slackEvent['text'] ?? '';
        $channel = $slackEvent['channel'] ?? '';
        $ts = $slackEvent['ts'] ?? null;
        $threadTs = $slackEvent['thread_ts'] ?? $ts;

        if (! $text || ! $channel || ! $threadTs) {
            return;
        }

        ConversationMemory::push($threadTs, $channel, 'user', $text);

        $history = ConversationMemory::history($threadTs, $channel);
        // Exclude the message we just pushed — agent gets it as the prompt
        $prior = array_slice($history, 0, -1);

        try {
            $reply = (string) BifrostAgent::make()
                ->withHistory($prior)
                ->prompt($text);

            $reply = trim($reply);
        } catch (\Throwable $e) {
            Log::error('SlackMessage: agent failed', ['error' => $e->getMessage()]);

            return;
        }

        if ($reply === '') {
            return;
        }

        ConversationMemory::push($threadTs, $channel, 'assistant', $reply);

        $this->post($channel, $reply, $ts);
    }

    private function shouldRespond(array $slackEvent): bool
    {
        $type = $slackEvent['type'] ?? '';

        if ($type !== 'message' && $type !== 'app_mention') {
            return false;
        }

        if (isset($slackEvent['bot_id']) || isset($slackEvent['bot_profile'])) {
            return false;
        }

        if (isset($slackEvent['subtype'])) {
            return false;
        }

        $channelType = $slackEvent['channel_type'] ?? '';

        if ($channelType === 'im') {
            return $type === 'message';
        }

        if ($type === 'app_mention') {
            return true;
        }

        return (bool) ($slackEvent['thread_ts'] ?? null);
    }

    private function post(string $channel, string $text, ?string $threadTs): void
    {
        $token = config('services.slack.bot_token');
        if (! $token) {
            return;
        }

        $payload = ['channel' => $channel, 'text' => $text];
        if ($threadTs) {
            $payload['thread_ts'] = $threadTs;
        }

        try {
            $response = Http::withToken($token)
                ->post('https://slack.com/api/chat.postMessage', $payload);

            if (! $response->json('ok')) {
                Log::warning('SlackMessage: post failed', ['error' => $response->json('error')]);
            }
        } catch (\Throwable $e) {
            Log::error('SlackMessage: HTTP error', ['error' => $e->getMessage()]);
        }
    }
}
