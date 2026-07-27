<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Generation;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Chat completion client for Ollama's /api/chat endpoint.
 */
final class OllamaGenerationClient implements GenerationClientInterface
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly SmartSearchConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<array{role: string, content: string}> $messages
     * @throws \RuntimeException on transport failure or an unexpected payload
     */
    public function complete(array $messages): string
    {
        $url = rtrim($this->configuration->getOllamaServerUrl(), '/') . '/api/chat';

        $response = $this->requestFactory->request($url, 'POST', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'model' => $this->configuration->getOllamaGenerationModel(),
                'messages' => $messages,
                // Ollama streams by default; this client returns a complete answer.
                'stream' => false,
                'options' => ['num_predict' => $this->configuration->getGenerationMaxTokens()],
            ], JSON_THROW_ON_ERROR),
            'timeout' => $this->configuration->getGenerationTimeout(),
            'http_errors' => false,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $this->logger->error('Ollama chat API returned unexpected status code', [
                'url' => $url,
                'status_code' => $statusCode,
                'response_body' => mb_substr((string) $response->getBody(), 0, 500),
            ]);
            throw new \RuntimeException(
                sprintf('Ollama chat API at "%s" returned HTTP %d.', $url, $statusCode),
                1_700_008_009,
            );
        }

        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $content = is_array($data) ? ($data['message']['content'] ?? null) : null;

        if (!is_string($content)) {
            $this->logger->error('Ollama chat API response has unexpected structure', [
                'url' => $url,
                'response_keys' => is_array($data) ? array_keys($data) : gettype($data),
            ]);
            throw new \RuntimeException(
                sprintf('Ollama chat API at "%s" returned an unexpected response structure.', $url),
                1_700_008_010,
            );
        }

        return $content;
    }
}
