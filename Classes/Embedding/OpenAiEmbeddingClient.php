<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Embedding;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Embedding client for the OpenAI /v1/embeddings endpoint.
 */
final class OpenAiEmbeddingClient implements EmbeddingClientInterface
{
    private const ENDPOINT = 'https://api.openai.com/v1/embeddings';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly SmartSearchConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return float[]
     * @throws \RuntimeException if no API key is configured, or on transport failure or an
     *         unexpected payload
     */
    public function embed(string $text): array
    {
        $apiKey = $this->configuration->getOpenAiApiKey();

        // Checked before the request: without it OpenAI returns 401 and the error a user sees is
        // "HTTP 401" rather than "you have not set an API key".
        if ($apiKey === '') {
            throw new \RuntimeException(
                'No OpenAI API key configured. Set openAiApiKey in the extension configuration.',
                1_700_008_003,
            );
        }

        $response = $this->requestFactory->request(self::ENDPOINT, 'POST', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
            ],
            'body' => json_encode([
                'input' => $text,
                'model' => $this->configuration->getOpenAiEmbeddingModel(),
            ], JSON_THROW_ON_ERROR),
            // The original client set no timeout here while its generation counterpart did, so a
            // hung request blocked a TYPO3 request for the PHP default.
            'timeout' => $this->configuration->getGenerationTimeout(),
            'http_errors' => false,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $this->logger->error('OpenAI embedding API returned unexpected status code', [
                'status_code' => $statusCode,
                'response_body' => mb_substr((string) $response->getBody(), 0, 500),
            ]);
            throw new \RuntimeException(
                sprintf('OpenAI embedding API returned HTTP %d.', $statusCode),
                1_700_008_004,
            );
        }

        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $embedding = is_array($data) ? ($data['data'][0]['embedding'] ?? null) : null;

        if (!is_array($embedding) || $embedding === []) {
            $this->logger->error('OpenAI embedding API response has unexpected structure', [
                'response_keys' => is_array($data) ? array_keys($data) : gettype($data),
            ]);
            throw new \RuntimeException(
                'OpenAI embedding API returned an unexpected response structure.',
                1_700_008_005,
            );
        }

        return array_map(static fn(mixed $v): float => (float) $v, array_values($embedding));
    }
}
