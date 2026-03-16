<?php

namespace App\Commands;

use App\Handlers\AnalyzeFailure;
use App\Handlers\SlackMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use LaravelZero\Framework\Commands\Command;

class Listen extends Command
{
    protected $signature = 'listen';

    protected $description = 'Subscribe to Bifrost Redis channels and handle events';

    public function handle(): void
    {
        $channels = ['bifrost.slack', 'bifrost.agent'];

        $this->info('bifrost-agent listening on: '.implode(', ', $channels));
        Log::info('bifrost-agent started', ['channels' => $channels]);

        Redis::connection('subscriber')->subscribe($channels, function (string $message, string $channel) {
            $this->dispatch($channel, $message);
        });
    }

    private function dispatch(string $channel, string $message): void
    {
        $event = json_decode($message, true);

        if (! is_array($event)) {
            Log::warning('Listen: invalid JSON on channel', ['channel' => $channel]);

            return;
        }

        Log::debug('Listen: received', ['channel' => $channel, 'event_type' => $event['event_type'] ?? '?']);

        try {
            match ($channel) {
                'bifrost.slack' => (new SlackMessage)->handle($event),
                'bifrost.agent' => $this->handleAgentEvent($event),
                default         => null,
            };
        } catch (\Throwable $e) {
            Log::error('Listen: handler threw', [
                'channel' => $channel,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function handleAgentEvent(array $event): void
    {
        $eventType = $event['event_type'] ?? $event['payload']['event'] ?? '';

        match ($eventType) {
            'analyze_failure' => (new AnalyzeFailure)->handle($event),
            default           => Log::debug('Listen: unhandled agent event', ['type' => $eventType]),
        };
    }
}
