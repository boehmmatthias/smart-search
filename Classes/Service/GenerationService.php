<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Service;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Generation\GenerationClientInterface;

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
     */
    public function generate(string $query, array $contextBlocks, ?string $systemPrompt = null): string
    {
        $context = implode("\n\n", $contextBlocks);

        $resolvedPrompt = $systemPrompt
            ?? $this->configuration->getSystemPrompt()
            ?? self::DEFAULT_SYSTEM_PROMPT;

        $messages = [
            [
                'role' => 'system',
                'content' => $resolvedPrompt,
            ],
            [
                'role' => 'user',
                'content' => "Documents:\n\n{$context}\n\nQuestion: {$query}",
            ],
        ];

        return $this->generationClient->complete($messages);
    }
}
