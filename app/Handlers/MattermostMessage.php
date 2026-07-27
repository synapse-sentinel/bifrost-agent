<?php

namespace App\Handlers;

use App\Agent\BifrostAgent;
use App\Services\MattermostService;
use App\Support\ConversationMemory;
use Illuminate\Support\Facades\Log;

class MattermostMessage
{
    public function __construct(private readonly MattermostService $mm) {}

    public function handle(array $event): void
    {
        $post = $event['post'] ?? [];

        if (! $this->shouldRespond($post)) {
            return;
        }

        $text = trim($post['message'] ?? '');
        $postId = $post['id'] ?? '';
        $channelId = $post['channel_id'] ?? '';

        if (! $text || ! $postId || ! $channelId) {
            return;
        }

        $threadId = $post['root_id'] ?: $postId;
        ConversationMemory::push($threadId, $channelId, 'user', $text);

        $history = ConversationMemory::history($threadId, $channelId);
        $prior = array_slice($history, 0, -1);

        try {
            $this->mm->sendTyping($channelId);

            $reply = (string) BifrostAgent::make()
                ->withHistory($prior)
                ->prompt($text);

            $reply = trim($reply);
        } catch (\Throwable $e) {
            Log::error('MattermostMessage: agent failed', ['error' => $e->getMessage()]);

            return;
        }

        if ($reply === '') {
            return;
        }

        ConversationMemory::push($threadId, $channelId, 'assistant', $reply);

        $this->post($channelId, $reply, $postId);
    }

    /**
     * Public for the WS listener (skip typing/log when we won't answer).
     *
     * @param  array<string, mixed>  $post
     */
    public function shouldRespondPublic(array $post): bool
    {
        return $this->shouldRespond($post);
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function shouldRespond(array $post): bool
    {
        $userId = $post['user_id'] ?? '';

        if ($userId === '') {
            return false;
        }

        $botId = config('services.mattermost.bot_user_id');
        if ($botId && $userId === $botId) {
            return false;
        }

        $type = $post['type'] ?? '';
        if ($type !== '' && $type !== 'normal') {
            return false;
        }

        $message = $post['message'] ?? '';
        // Mattermost may wrap mentions; also accept bare handle.
        if ($message !== '' && ! preg_match('/@bifrost\b/i', $message)) {
            return false;
        }

        return true;
    }

    private function post(string $channelId, string $text, string $rootId): void
    {
        $result = $this->mm->createPost($channelId, $text, $rootId);

        if (! $result) {
            Log::warning('MattermostMessage: post failed');
        }
    }
}
