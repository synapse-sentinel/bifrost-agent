<?php

namespace App\Commands;

use App\Handlers\MattermostMessage;
use App\Services\MattermostService;
use App\Support\ConversationMemory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;

class ListenMattermost extends Command
{
    protected $signature = 'bifrost:listen-mattermost';

    protected $description = 'Subscribe to Mattermost as Bifrost — receives @mentions and channel messages';

    private string $botUserId = '';

    private ?WebSocket $ws = null;

    private int $wsSeq = 2;

    private bool $reconnecting = false;

    private int $backoffSeconds = 1;

    private ?TimerInterface $heartbeatTimer = null;

    private bool $selfInitiatedClose = false;

    /**
     * Auth success announced once per socket. Mattermost answers every
     * 30s heartbeat ping with status=OK; treating those as re-auth made
     * a healthy connection look like a reconnect flap in the journal
     * (same class of bug Lexi fixed in ListenMattermost).
     */
    private bool $announcedAuth = false;

    public function handle(): int
    {
        $token = (string) config('services.mattermost.bot_token', '');
        if ($token === '') {
            $this->error('MATTERMOST_BOT_TOKEN not configured.');

            return self::FAILURE;
        }

        $url = str_replace(['http://', 'https://'], ['ws://', 'wss://'],
            (string) config('services.mattermost.url', 'http://localhost:8065')
        ).'/api/v4/websocket';

        if (! $this->resolveBotUserId($url, $token)) {
            $this->error('Could not resolve bot user ID.');

            return self::FAILURE;
        }

        $this->info("Connecting to Mattermost WebSocket as Bifrost: {$url}");

        $loop = Loop::get();
        $this->scheduleConnect($url, $token, 0);
        $loop->run();

        return self::SUCCESS;
    }

    private function scheduleConnect(string $wsUrl, string $token, int $delaySeconds): void
    {
        if ($this->reconnecting) {
            return;
        }
        $this->reconnecting = true;

        if ($delaySeconds > 0) {
            $this->line("Reconnecting in {$delaySeconds}s...");
        }

        Loop::get()->addTimer(max(0, $delaySeconds), function () use ($wsUrl, $token): void {
            $this->connect($wsUrl, $token);
        });
    }

    private function connect(string $wsUrl, string $token): void
    {
        $connector = new Connector(Loop::get());
        $lastMessage = time();

        $connector($wsUrl)->then(
            function (WebSocket $conn) use ($token, $wsUrl, &$lastMessage): void {
                $this->ws = $conn;
                $this->reconnecting = false;
                $this->wsSeq = 2;
                $this->announcedAuth = false;
                $this->info('Connected — authenticating...');

                $conn->send(json_encode([
                    'seq' => 1,
                    'action' => 'authentication_challenge',
                    'data' => ['token' => $token],
                ]));

                $conn->on('message', function (MessageInterface $msg) use (&$lastMessage): void {
                    $lastMessage = time();
                    $this->handleFrame((string) $msg);
                });

                $conn->on('close', function ($code = null, $reason = null) use ($wsUrl, $token): void {
                    $this->onClose((int) ($code ?? 0), (string) ($reason ?? ''), $wsUrl, $token);
                });

                $this->heartbeatTimer = Loop::get()->addPeriodicTimer(30, function () use ($conn, &$lastMessage): void {
                    try {
                        $conn->send(json_encode(['seq' => $this->wsSeq++, 'action' => 'ping']));
                    } catch (\Throwable) {
                        $this->selfInitiatedClose = true;
                        $conn->close();

                        return;
                    }

                    if (time() - $lastMessage > 120) {
                        $this->warn('Heartbeat timeout — recycling.');
                        $this->selfInitiatedClose = true;
                        $conn->close();
                    }
                });
            },
            function (\Throwable $e) use ($wsUrl, $token): void {
                $this->reconnecting = false;
                $this->error("Connection failed: {$e->getMessage()}");
                $this->scheduleConnect($wsUrl, $token, $this->nextBackoff());
            }
        );
    }

    private function onClose(int $code, string $reason, string $wsUrl, string $token): void
    {
        $this->ws = null;
        if ($this->heartbeatTimer !== null) {
            Loop::get()->cancelTimer($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }

        $self = $this->selfInitiatedClose;
        $this->warn("Connection closed: {$code} {$reason}".($self ? ' (self)' : ''));

        $delay = $this->nextBackoff($code);
        $this->selfInitiatedClose = false;
        $this->scheduleConnect($wsUrl, $token, $delay);
    }

    private function nextBackoff(int $closeCode = 0): int
    {
        if ($closeCode === 1000 && ! $this->selfInitiatedClose) {
            return 30;
        }

        $delay = min($this->backoffSeconds, 30);
        $this->backoffSeconds = min($this->backoffSeconds * 2, 30);

        return $delay;
    }

    private function handleFrame(string $raw): void
    {
        $event = json_decode($raw, true);
        if (! is_array($event)) {
            return;
        }

        if (($event['status'] ?? '') === 'OK') {
            // Every OK frame lands here — including pong replies to our
            // 30s heartbeat ping. Announce once per connection only.
            if (! $this->announcedAuth) {
                $this->announcedAuth = true;
                $this->info('Authenticated — Bifrost is listening.');
            }
            $this->backoffSeconds = 1;

            return;
        }

        if (($event['event'] ?? '') === 'hello') {
            return;
        }

        if (($event['event'] ?? '') === 'posted') {
            $this->handlePosted($event);
        }
    }

    private function handlePosted(array $event): void
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $postJson = $data['post'] ?? '';
        $post = is_string($postJson) ? json_decode($postJson, true) : $postJson;
        if (! is_array($post)) {
            return;
        }

        $userId = (string) ($post['user_id'] ?? '');

        if ($userId === '' || $userId === $this->botUserId) {
            return;
        }

        $message = (string) ($post['message'] ?? '');
        $channelId = (string) ($post['channel_id'] ?? '');
        $channelName = (string) ($data['channel_name'] ?? '');
        $senderName = (string) ($data['sender_name'] ?? '');
        $postId = (string) ($post['id'] ?? '');
        $rootId = (string) ($post['root_id'] ?? '');

        if ($message === '' || $channelId === '') {
            return;
        }

        // Only log/type when we might answer — @bifrost mentions (handler
        // re-checks). Avoids typing-indicator spam on every channel post.
        $handler = new MattermostMessage(app(MattermostService::class));
        if (! $handler->shouldRespondPublic($post)) {
            return;
        }

        $this->line("[Bifrost] [{$senderName}] [{$channelName}] ".mb_substr($message, 0, 120));
        $this->sendTyping($channelId);

        try {
            $handler->handle([
                'post' => array_merge($post, [
                    'channel_id' => $channelId,
                    'root_id' => $rootId,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('Bifrost Mattermost: handler failed', ['error' => $e->getMessage()]);
            $this->error("Handler failed: {$e->getMessage()}");
        }
    }

    private function sendTyping(string $channelId): void
    {
        if (! $this->ws instanceof WebSocket) {
            return;
        }

        try {
            $this->ws->send(json_encode([
                'action' => 'user_typing',
                'seq' => $this->wsSeq++,
                'data' => ['channel_id' => $channelId],
            ]));
        } catch (\Throwable) {
        }
    }

    private function resolveBotUserId(string $wsUrl, string $token): bool
    {
        $baseUrl = str_replace(['ws://', 'wss://'], ['http://', 'https://'], $wsUrl);
        $apiUrl = str_replace('/api/v4/websocket', '', $baseUrl);

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$apiUrl}/api/v4/users/me");

            if ($response->successful()) {
                $this->botUserId = (string) $response->json('id', '');
                $this->info("Bifrost bot user ID: {$this->botUserId}");

                return $this->botUserId !== '';
            }
        } catch (\Throwable $e) {
            $this->error("Failed to resolve bot user ID: {$e->getMessage()}");
        }

        return false;
    }
}
