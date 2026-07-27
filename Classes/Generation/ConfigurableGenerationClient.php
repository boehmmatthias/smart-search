<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Generation;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;

/**
 * Dispatches to whichever generation client the `generationProvider` setting names.
 *
 * Chosen independently of the embedding provider: running embeddings locally on llama.cpp while
 * generating with a hosted model is a normal arrangement, and the two are not comparable
 * anyway — vectors from different models cannot be mixed, but answers can come from anywhere.
 */
final class ConfigurableGenerationClient implements GenerationClientInterface
{
    public function __construct(
        private readonly SmartSearchConfiguration $configuration,
        private readonly GenerationClientInterface $llamaCpp,
        private readonly GenerationClientInterface $ollama,
        private readonly GenerationClientInterface $openAi,
    ) {}

    /**
     * @param array<array{role: string, content: string}> $messages
     */
    public function complete(array $messages): string
    {
        return $this->resolve()->complete($messages);
    }

    private function resolve(): GenerationClientInterface
    {
        return match ($this->configuration->getGenerationProvider()) {
            'ollama' => $this->ollama,
            'openai' => $this->openAi,
            default => $this->llamaCpp,
        };
    }
}
