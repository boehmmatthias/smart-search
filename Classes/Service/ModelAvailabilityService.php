<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Service;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Reports whether the configured backends can be reached, so a consumer can hide semantic
 * search or RAG features rather than let them fail in front of a visitor.
 *
 * Each side follows its own provider setting. Probing the llama.cpp URLs regardless — which is
 * what this used to do — reported an Ollama or OpenAI install as unavailable while it was
 * working perfectly, and hid exactly the features it was asked to guard.
 */
class ModelAvailabilityService
{
    private ?bool $embeddingAvailable = null;
    private ?bool $generationAvailable = null;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly SmartSearchConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {}

    public function isEmbeddingServerAvailable(): bool
    {
        $this->embeddingAvailable ??= $this->checkProvider(
            $this->configuration->getEmbeddingProvider(),
            $this->configuration->getEmbeddingServerUrl(),
            'embedding',
        );

        return $this->embeddingAvailable;
    }

    public function isGenerationServerAvailable(): bool
    {
        $this->generationAvailable ??= $this->checkProvider(
            $this->configuration->getGenerationProvider(),
            $this->configuration->getGenerationServerUrl(),
            'generation',
        );

        return $this->generationAvailable;
    }

    /**
     * @param string $provider One of the values SmartSearchConfiguration normalises to.
     * @param string $llamaCppUrl Base URL used when the provider is llama.cpp.
     */
    private function checkProvider(string $provider, string $llamaCppUrl, string $serverType): bool
    {
        return match ($provider) {
            // Ollama has no /health endpoint. /api/tags lists the installed models, so a 200
            // means the API is up rather than merely that something answered the port.
            'ollama' => $this->checkUrl(
                rtrim($this->configuration->getOllamaServerUrl(), '/') . '/api/tags',
                $serverType,
            ),
            // OpenAI exposes no free health endpoint — the cheapest probe is a billable API
            // call, which is not something to issue on a page render. A missing API key is
            // also the only part of this that fails locally, and the only part worth telling
            // a consumer about before they try.
            'openai' => $this->hasOpenAiKey($serverType),
            default => $this->checkUrl($llamaCppUrl . '/health', $serverType),
        };
    }

    private function hasOpenAiKey(string $serverType): bool
    {
        if ($this->configuration->getOpenAiApiKey() !== '') {
            return true;
        }

        $this->logger->debug('Smart search {serverType} provider is OpenAI but no API key is configured', [
            'serverType' => $serverType,
        ]);

        return false;
    }

    private function checkUrl(string $url, string $serverType): bool
    {
        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => 2,
                'http_errors' => false,
            ]);

            $available = $response->getStatusCode() < 300;

            if (!$available) {
                $this->logger->debug('Smart search {serverType} server health check failed', [
                    'serverType' => $serverType,
                    'url' => $url,
                    'status_code' => $response->getStatusCode(),
                ]);
            }

            return $available;
        } catch (Throwable $e) {
            $this->logger->debug('Smart search {serverType} server is unreachable', [
                'serverType' => $serverType,
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
