<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

class ConversationMemory
{
    private const TTL = 86400; // 24 hours

    public static function push(string $threadTs, string $channel, string $role, string $content): void
    {
        $key = self::key($threadTs, $channel);

        Redis::rpush($key, json_encode(['role' => $role, 'content' => $content]));
        Redis::expire($key, self::TTL);
    }

    /** @return array<int, array{role: string, content: string}> */
    public static function history(string $threadTs, string $channel): array
    {
        $key = self::key($threadTs, $channel);
        $entries = Redis::lrange($key, 0, -1);

        return array_map(fn ($e) => json_decode($e, true), $entries);
    }

    private static function key(string $threadTs, string $channel): string
    {
        return "agent_conversation:{$channel}:{$threadTs}";
    }
}
