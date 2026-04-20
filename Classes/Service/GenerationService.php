<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Service;

use BoehmMatthias\SmartSearch\Generation\GenerationClientInterface;

class GenerationService
{
    public function __construct(
        private readonly GenerationClientInterface $generationClient,
    ) {}

    /**
     * Generate an LLM answer for the given query using pre-formatted context blocks.
     *
     * @param string[] $contextBlocks Each element is one formatted block of context text.
     */
    public function generate(string $query, array $contextBlocks): string
    {
        $context = implode("\n\n", $contextBlocks);

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a helpful assistant for a knowledge base. '
                    . 'Answer the question using only the provided documents. '
                    . 'Be detailed and cite your sources by their uid (e.g. [1], [2]).',
            ],
            [
                'role' => 'user',
                'content' => "Documents:\n\n{$context}\n\nQuestion: {$query}",
            ],
        ];

        return $this->generationClient->complete($messages);
    }
}
