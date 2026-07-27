<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\ValueObject;

/**
 * An immutable sequence of prior conversation turns.
 *
 * Holds user and assistant messages only. The system message is prepended by
 * GenerationService from the configured or per-call prompt, so putting one in here would
 * produce two system messages in the request.
 */
final class ConversationHistory
{
    /**
     * @param array<array{role: string, content: string}> $messages
     */
    public function __construct(
        private readonly array $messages = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function withUserMessage(string $content): self
    {
        return $this->append('user', $content);
    }

    public function withAssistantMessage(string $content): self
    {
        return $this->append('assistant', $content);
    }

    /**
     * Returns a history keeping only the most recent $maxTurns exchanges, where one turn is a
     * user message and the assistant's reply.
     *
     * Trims from the front, so the most recent context survives — which is what a context
     * window running out actually needs.
     */
    public function truncated(int $maxTurns): self
    {
        if ($maxTurns <= 0) {
            return new self([]);
        }

        $maxMessages = $maxTurns * 2;

        if (count($this->messages) <= $maxMessages) {
            return $this;
        }

        return new self(array_slice($this->messages, -$maxMessages));
    }

    /**
     * @return array<array{role: string, content: string}>
     */
    public function toArray(): array
    {
        return $this->messages;
    }

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    public function count(): int
    {
        return count($this->messages);
    }

    private function append(string $role, string $content): self
    {
        return new self([...$this->messages, ['role' => $role, 'content' => $content]]);
    }
}
