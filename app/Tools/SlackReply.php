<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SlackReply implements Tool
{
    public function description(): Stringable|string
    {
        return 'Post a message to a Slack channel or thread. Use this to send replies, notifications, or alerts.';
    }

    public function handle(Request $request): Stringable|string
    {
        $channel = $request['channel'] ?? '';
        $text = $request['text'] ?? '';
        $threadTs = $request['thread_ts'] ?? null;

        if (! $channel || ! $text) {
            return 'channel and text are required.';
        }

        $token = config('services.slack.bot_token');
        if (! $token) {
            return 'Slack token not configured.';
        }

        $payload = ['channel' => $channel, 'text' => $text];
        if ($threadTs) {
            $payload['thread_ts'] = $threadTs;
        }

        try {
            $response = Http::withToken($token)
                ->post('https://slack.com/api/chat.postMessage', $payload);

            if (! $response->json('ok')) {
                $error = $response->json('error', 'unknown');
                Log::warning('SlackReply: API error', ['error' => $error]);

                return "Slack error: {$error}";
            }

            return 'Message sent.';
        } catch (\Throwable $e) {
            Log::error('SlackReply: request failed', ['error' => $e->getMessage()]);

            return 'Failed to post to Slack: '.$e->getMessage();
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'channel'   => $schema->string()->description('Slack channel ID or name')->required(),
            'text'      => $schema->string()->description('Message text (supports mrkdwn)')->required(),
            'thread_ts' => $schema->string()->description('Thread timestamp to reply in a thread'),
        ];
    }
}
