<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Embedding;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Embedding client for Ollama's /api/embeddings endpoint.
 */
final class OllamaEmbeddingClient implements EmbeddingClientInterface
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly SmartSearchConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return float[]
     * @throws \RuntimeException on transport failure or an unexpected payload
     */
    public function embed(string $text): array
    {
        $url = rtrim($this->configuration->getOllamaServerUrl(), '/') . '/api/embeddings';

        $response = $this->requestFactory->request($url, 'POST', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'model' => $this->configuration->getOllamaEmbeddingModel(),
                'prompt' => $text,
            ], JSON_THROW_ON_ERROR),
            'timeout' => $this->configuration->getGenerationTimeout(),
            'http_errors' => false,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $this->logger->error('Ollama embedding API returned unexpected status code', [
                'url' => $url,
                'status_code' => $statusCode,
                'response_body' => mb_substr((string) $response->getBody(), 0, 500),
            ]);
            throw new \RuntimeException(
                sprintf('Ollama embedding API at "%s" returned HTTP %d.', $url, $statusCode),
                1_700_008_001,
            );
        }

        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $embedding = is_array($data) ? ($data['embedding'] ?? null) : null;

        // An empty array is rejected alongside a missing key: returning [] would store a
        // zero-length vector that later scores 0.0 against everything, which is indistinguishable
        // from a genuinely unrelated document.
        if (!is_array($embedding) || $embedding === []) {
            $this->logger->error('Ollama embedding API response has unexpected structure', [
                'url' => $url,
                'response_keys' => is_array($data) ? array_keys($data) : gettype($data),
            ]);
            throw new \RuntimeException(
                sprintf('Ollama embedding API at "%s" returned an unexpected response structure.', $url),
                1_700_008_002,
            );
        }

        return array_map(static fn(mixed $v): float => (float) $v, array_values($embedding));
    }
}
