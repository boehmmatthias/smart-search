<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Embedding;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;

/**
 * Dispatches to whichever embedding client the `embeddingProvider` setting names.
 *
 * This is what makes the alternative clients reachable at all. Shipping them without it left
 * four classes that nothing could resolve and a configuration UI that changed nothing — a
 * consumer had to override the interface alias in their own Services.yaml to use any of them.
 *
 * Constructing all three is cheap: each holds a RequestFactory, the configuration and a logger,
 * and none of them opens a connection until embed() is called.
 *
 * The three are typed as the interface and wired explicitly in Services.yaml, so a consumer can
 * substitute any of the three slots without touching this class.
 */
final class ConfigurableEmbeddingClient implements EmbeddingClientInterface
{
    public function __construct(
        private readonly SmartSearchConfiguration $configuration,
        private readonly EmbeddingClientInterface $llamaCpp,
        private readonly EmbeddingClientInterface $ollama,
        private readonly EmbeddingClientInterface $openAi,
    ) {}

    /**
     * @return float[]
     */
    public function embed(string $text): array
    {
        return $this->resolve()->embed($text);
    }

    private function resolve(): EmbeddingClientInterface
    {
        return match ($this->configuration->getEmbeddingProvider()) {
            'ollama' => $this->ollama,
            'openai' => $this->openAi,
            default => $this->llamaCpp,
        };
    }
}
