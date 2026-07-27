<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Service;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Generation\GenerationClientInterface;
use BoehmMatthias\SmartSearch\ValueObject\ConversationHistory;

class GenerationService
{
    private const DEFAULT_SYSTEM_PROMPT = 'You are a helpful assistant for a knowledge base. '
        . 'Answer the question using only the provided documents. '
        . 'Be detailed and cite your sources by their uid (e.g. [1], [2]).';

    public function __construct(
        private readonly GenerationClientInterface $generationClient,
        private readonly SmartSearchConfiguration $configuration,
    ) {}

    /**
     * Generate an LLM answer for the given query using pre-formatted context blocks.
     *
     * @param string[] $contextBlocks Each element is one formatted block of context text.
     * @param string|null $systemPrompt Override the system prompt; falls back to extension config, then built-in default.
     * @param ConversationHistory|null $history Prior turns, inserted between the system message
     *        and the current question so the model can resolve follow-ups like "and the second one?".
     *        Appended after $systemPrompt rather than sharing its position — both are optional and
     *        independent, and parameter names are part of this extension's public contract.
     */
    public function generate(
        string $query,
        array $contextBlocks,
        ?string $systemPrompt = null,
        ?ConversationHistory $history = null,
    ): string {
        return $this->generationClient->complete(
            $this->buildMessages($query, $contextBlocks, $systemPrompt, $history),
        );
    }

    /**
     * @param string[] $contextBlocks
     * @return array<array{role: string, content: string}>
     */
    private function buildMessages(
        string $query,
        array $contextBlocks,
        ?string $systemPrompt,
        ?ConversationHistory $history,
    ): array {
        $context = implode("\n\n", $contextBlocks);

        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt
                    ?? $this->configuration->getSystemPrompt()
                    ?? self::DEFAULT_SYSTEM_PROMPT,
            ],
        ];

        if ($history !== null && !$history->isEmpty()) {
            array_push($messages, ...$history->toArray());
        }

        $messages[] = [
            'role' => 'user',
            'content' => "Documents:\n\n{$context}\n\nQuestion: {$query}",
        ];

        return $messages;
    }
}
