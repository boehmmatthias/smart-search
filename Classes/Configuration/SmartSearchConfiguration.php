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
        return trim((string) ($this->config['embeddingServerUrl'] ?? 'http://localhost:8080'));
    }

    public function getGenerationServerUrl(): string
    {
        return trim((string) ($this->config['generationServerUrl'] ?? 'http://localhost:8081'));
    }

    public function getGenerationMaxTokens(): int
    {
        return (int) ($this->config['generationMaxTokens'] ?? 512);
    }

    public function getGenerationTimeout(): int
    {
        return (int) ($this->config['generationTimeout'] ?? 300);
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

    public function getSystemPrompt(): ?string
    {
        $prompt = trim((string) ($this->config['systemPrompt'] ?? ''));
        return $prompt !== '' ? $prompt : null;
    }
}
