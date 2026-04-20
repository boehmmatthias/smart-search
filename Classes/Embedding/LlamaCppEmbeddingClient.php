<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Embedding;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;

class LlamaCppEmbeddingClient implements EmbeddingClientInterface
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly SmartSearchConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return float[]
     * @throws \RuntimeException if the embedding server returns a non-200 response or an unexpected payload
     */
    public function embed(string $text): array
    {
        $url = $this->configuration->getEmbeddingServerUrl() . '/embedding';

        // Retry with progressively shorter text if the server rejects the input
        // as too long (HTTP 400). Each attempt halves the text.
        $response = null;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $response = $this->requestFactory->request(
                $url,
                'POST',
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => json_encode(['content' => $text], JSON_THROW_ON_ERROR),
                    'http_errors' => false,
                ]
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 400) {
                break;
            }

            $this->logger->warning('Embedding server rejected input as too long (HTTP 400), retrying with halved text', [
                'url' => $url,
                'attempt' => $attempt + 1,
                'text_length' => mb_strlen($text),
            ]);

            $text = mb_substr($text, 0, (int) (mb_strlen($text) / 2));
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            $body = (string) $response->getBody();
            $this->logger->error('Embedding server returned unexpected status code', [
                'url' => $url,
                'status_code' => $statusCode,
                'response_body' => mb_substr($body, 0, 500),
            ]);
            throw new \RuntimeException(
                sprintf('Embedding server at "%s" returned HTTP %d.', $url, $statusCode),
                1_700_000_001
            );
        }

        $data = json_decode(
            (string) $response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!isset($data[0]['embedding'][0]) || !is_array($data[0]['embedding'][0])) {
            $this->logger->error('Embedding server response has unexpected structure', [
                'url' => $url,
                'response_keys' => is_array($data) ? array_keys($data) : gettype($data),
            ]);
            throw new \RuntimeException(
                sprintf('Embedding server at "%s" returned an unexpected response structure.', $url),
                1_700_000_002
            );
        }

        return $data[0]['embedding'][0];
    }
}
