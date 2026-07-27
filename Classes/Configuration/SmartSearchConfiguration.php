<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class SmartSearchConfiguration
{
    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        $this->config = (array) $extensionConfiguration->get('smart_search');
    }

    public function getEmbeddingServerUrl(): string
    {
        return $this->nonEmpty($this->config['embeddingServerUrl'] ?? null, 'http://localhost:8080');
    }

    public function getGenerationServerUrl(): string
    {
        return $this->nonEmpty($this->config['generationServerUrl'] ?? null, 'http://localhost:8081');
    }

    /**
     * Clamped to a positive value: (int) '' is 0, and asking a model for 0 tokens requests an
     * empty answer rather than falling back to anything.
     */
    public function getGenerationMaxTokens(): int
    {
        return $this->positiveOr($this->config['generationMaxTokens'] ?? null, 512);
    }

    /**
     * Clamped to a positive value. Guzzle reads a timeout of 0 as "wait indefinitely", so a
     * cleared field turned a hung inference server into a request that never returns. This is
     * also the timeout both the Ollama and OpenAI embedding clients use, so it is not confined
     * to the generation path.
     */
    public function getGenerationTimeout(): int
    {
        return $this->positiveOr($this->config['generationTimeout'] ?? null, 300);
    }

    /**
     * Maximum characters of text sent to the embedding server.
     *
     * Clamped to a positive value. The `??` fallback only fires when the key is absent, but the
     * Install Tool stores every setting as a string — so clearing the field leaves '' and
     * (int) '' is 0. That made normalise() truncate every document to the empty string:
     * identical vectors throughout the collection, every content_hash equal to the MD5 of '',
     * and arbitrary documents returned at indistinguishable scores. A negative value was worse
     * still, because mb_substr($text, 0, -50) strips the tail instead of truncating.
     */
    public function getEmbeddingContextLength(): int
    {
        $configured = (int) ($this->config['embeddingContextLength'] ?? 6000);

        return $configured > 0 ? $configured : 6000;
    }

    public function getRagTopK(): int
    {
        return (int) ($this->config['ragTopK'] ?? 5);
    }

    public function getDocumentContextLength(): int
    {
        return (int) ($this->config['documentContextLength'] ?? 800);
    }

    public function getSemanticThreshold(): float
    {
        return (float) ($this->config['semanticThreshold'] ?? 0.30);
    }

    /**
     * TTL in seconds for cached findSimilar() results. 0 disables query caching entirely.
     */
    public function getQueryCacheTtl(): int
    {
        $ttl = (int) ($this->config['queryCacheTtl'] ?? 3600);

        return max(0, $ttl);
    }

    /**
     * Which embedding backend to use: 'llamacpp' (default), 'ollama' or 'openai'.
     *
     * An unrecognised value falls back to llama.cpp rather than throwing. Switching providers
     * mid-life is already a re-embed — vectors from different models are not comparable — so a
     * typo silently changing the model would be far worse than one that does nothing.
     */
    public function getEmbeddingProvider(): string
    {
        return $this->normaliseProvider($this->config['embeddingProvider'] ?? null);
    }

    /**
     * Which generation backend to use: 'llamacpp' (default), 'ollama' or 'openai'. Chosen
     * independently of the embedding provider.
     */
    public function getGenerationProvider(): string
    {
        return $this->normaliseProvider($this->config['generationProvider'] ?? null);
    }

    private function normaliseProvider(mixed $value): string
    {
        $provider = strtolower(trim((string) $value));

        return in_array($provider, ['llamacpp', 'ollama', 'openai'], true) ? $provider : 'llamacpp';
    }

    public function getOpenAiApiKey(): string
    {
        return trim((string) ($this->config['openAiApiKey'] ?? ''));
    }

    public function getOpenAiEmbeddingModel(): string
    {
        return $this->nonEmpty($this->config['openAiEmbeddingModel'] ?? null, 'text-embedding-3-small');
    }

    public function getOpenAiGenerationModel(): string
    {
        return $this->nonEmpty($this->config['openAiGenerationModel'] ?? null, 'gpt-4o-mini');
    }

    public function getOllamaServerUrl(): string
    {
        return $this->nonEmpty($this->config['ollamaServerUrl'] ?? null, 'http://localhost:11434');
    }

    public function getOllamaEmbeddingModel(): string
    {
        return $this->nonEmpty($this->config['ollamaEmbeddingModel'] ?? null, 'nomic-embed-text');
    }

    public function getOllamaGenerationModel(): string
    {
        return $this->nonEmpty($this->config['ollamaGenerationModel'] ?? null, 'llama3.2');
    }

    /**
     * The Install Tool writes '' for a cleared field, which ?? does not catch — the key exists.
     */
    private function nonEmpty(mixed $value, string $default): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : $default;
    }

    /**
     * Integer counterpart to nonEmpty(): a cleared field casts to 0, and every integer setting
     * here is a limit for which 0 is not a weaker value but a different, broken meaning.
     */
    private function positiveOr(mixed $value, int $default): int
    {
        $configured = (int) $value;

        return $configured > 0 ? $configured : $default;
    }

    public function getSystemPrompt(): ?string
    {
        $prompt = trim((string) ($this->config['systemPrompt'] ?? ''));
        return $prompt !== '' ? $prompt : null;
    }
}
