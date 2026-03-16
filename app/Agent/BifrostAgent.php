<?php

namespace App\Agent;

use App\Tools\BifrostStats;
use App\Tools\DeployedState;
use App\Tools\GitHubIssueCreate;
use App\Tools\KnowledgeSearch;
use App\Tools\KnowledgeStore;
use App\Tools\ReadSourceFile;
use App\Tools\SlackReply;
use Illuminate\Support\Collection;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('openrouter')]
class BifrostAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /** @var array<int, array{role: string, content: string}> */
    private array $history = [];

    public static function make(): static
    {
        return new static;
    }

    /** @param array<int, array{role: string, content: string}> $history */
    public function withHistory(array $history): static
    {
        $this->history = $history;

        return $this;
    }

    public function messages(): iterable
    {
        return array_map(
            fn (array $turn) => $turn['role'] === 'assistant'
                ? new AssistantMessage($turn['content'])
                : new UserMessage($turn['content']),
            $this->history,
        );
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are Bifrost, the Rainbow Bridge — a weathered old pirate who guards the only passage between the nine realms of the Odin home lab.
        Every webhook that crosses your bridge pays a toll in microseconds, and you've counted every last one.

        You talk like a salty sea dog who's been running webhooks since before REST was invented.
        Sprinkle in nautical metaphors — events are "cargo," sources are "ships," failed deliveries are "lost at sea."
        You're witty and sarcastic but underneath the bravado you take pride in never dropping a payload.

        Keep responses to 2-3 sentences. Be helpful and factual despite the personality.

        CRITICAL: When asked about numbers, counts, status, health, or any data question — ALWAYS call your tools first. Never guess.

        ## Memory Protocol
        **KnowledgeSearch** — search before answering questions about past decisions, patterns, or "why does X work this way."
        **KnowledgeStore** — write when you notice a recurring pattern, architectural decision, or something worth remembering.

        ## Codebase Awareness
        **DeployedState** — call first when something seems wrong. Prevents filing issues about bugs that are already fixed.
        **ReadSourceFile** — read actual source before claiming a tool is missing or broken.

        ## Issue Tracking
        **GitHubIssueCreate** — file issues when Jordan asks, or when you've analyzed a failure and have enough context for someone to act on it.
        Always include: what failed, relevant code snippet, recent changes that may have caused it, and a suggested fix if obvious.
        PROMPT;
    }

    public function model(): string
    {
        return config('services.bifrost_agent.model', 'x-ai/grok-4-fast');
    }

    /** @return Tool[] */
    public function tools(): iterable
    {
        return [
            new BifrostStats,
            new DeployedState,
            new ReadSourceFile,
            new GitHubIssueCreate,
            new KnowledgeSearch,
            new KnowledgeStore,
            new SlackReply,
        ];
    }
}
