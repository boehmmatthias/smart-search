<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Embedding;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

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

        $response = $this->requestFactory->request(
            $url,
            'POST',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['content' => $text], JSON_THROW_ON_ERROR),
                'http_errors' => false,
            ],
        );

        $statusCode = $response->getStatusCode();

        // A 400 means the server rejected the input, in practice because it is longer than the
        // model's context window. This used to halve the text and retry up to four times, which
        // produced a vector for as little as an eighth of the document. The caller hashes the
        // *full* text before calling, so that partial vector was stored against the full-text
        // hash and every later embedAndStore() short-circuited on it — the row could never be
        // repaired, not even after raising --ctx-size. Failing loudly is the only honest option;
        // embeddingContextLength and the chunking strategies are the supported ways to fit text
        // into the window.
        if ($statusCode === 400) {
            $this->logger->error('Embedding server rejected the input (HTTP 400)', [
                'url' => $url,
                'text_length' => mb_strlen($text),
            ]);
            throw new \RuntimeException(
                sprintf(
                    'Embedding server at "%s" rejected %d characters of input. Lower the '
                    . 'embeddingContextLength setting to match the model\'s context window, or '
                    . 'split the text with a ChunkingStrategyInterface.',
                    $url,
                    mb_strlen($text),
                ),
                1_700_000_003,
            );
        }

        if ($statusCode !== 200) {
            $body = (string) $response->getBody();
            $this->logger->error('Embedding server returned unexpected status code', [
                'url' => $url,
                'status_code' => $statusCode,
                'response_body' => mb_substr($body, 0, 500),
            ]);
            throw new \RuntimeException(
                sprintf('Embedding server at "%s" returned HTTP %d.', $url, $statusCode),
                1_700_000_001,
            );
        }

        $data = json_decode(
            (string) $response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        // The emptiness check matters as much as the shape check. A payload of
        // [{"embedding":[[]]}] satisfies isset() and is_array(), and returning [] from it is not
        // harmless: an empty stored vector packs to a zero-length blob, and if the query vector
        // is also empty the dimension guard passes, cosineSimilarity([], []) returns 0.0 for
        // every row, and array_slice hands back the first topK rows presented as ranked hits.
        if (!isset($data[0]['embedding'][0])
            || !is_array($data[0]['embedding'][0])
            || $data[0]['embedding'][0] === []
        ) {
            $this->logger->error('Embedding server response has unexpected structure', [
                'url' => $url,
                'response_keys' => is_array($data) ? array_keys($data) : gettype($data),
            ]);
            throw new \RuntimeException(
                sprintf('Embedding server at "%s" returned an unexpected response structure.', $url),
                1_700_000_002,
            );
        }

        // Normalised the same way the Ollama and OpenAI clients do. json_decode() yields an int
        // for an exact-integer JSON number, so returning the decoded value as-is could hand back
        // an int[] from a method declared float[], and a non-list if the server ever emitted an
        // object rather than an array.
        return array_map(
            static fn(mixed $component): float => (float) $component,
            array_values($data[0]['embedding'][0]),
        );
    }
}
