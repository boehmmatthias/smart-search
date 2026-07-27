<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Generation;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Streams chat completions from a llama.cpp / OpenAI-compatible server over Server-Sent Events.
 *
 * Each event looks like:
 *   data: {"choices":[{"delta":{"content":"token"}}]}
 * terminated by:
 *   data: [DONE]
 */
final class LlamaCppStreamingGenerationClient implements StreamingGenerationClientInterface
{
    private const READ_BYTES = 1024;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly SmartSearchConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<array{role: string, content: string}> $messages
     * @param callable(string): void $onChunk
     */
    public function stream(array $messages, callable $onChunk): void
    {
        $url = $this->configuration->getGenerationServerUrl() . '/v1/chat/completions';

        // RequestFactory::request() passes $options straight to Guzzle, so 'stream' => true is
        // honoured and the body is not buffered before this method sees it.
        $response = $this->requestFactory->request($url, 'POST', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'messages' => $messages,
                'max_tokens' => $this->configuration->getGenerationMaxTokens(),
                'stream' => true,
            ], JSON_THROW_ON_ERROR),
            'timeout' => $this->configuration->getGenerationTimeout(),
            'http_errors' => false,
            'stream' => true,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $this->logger->error('Streaming generation server returned unexpected status code', [
                'url' => $url,
                'status_code' => $statusCode,
                'response_body' => mb_substr((string) $response->getBody(), 0, 500),
            ]);
            throw new \RuntimeException(
                sprintf('Streaming generation server at "%s" returned HTTP %d.', $url, $statusCode),
                1_700_003_001,
            );
        }

        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(self::READ_BYTES);

            // A read boundary can fall anywhere, including mid-line and mid-JSON, so only
            // complete lines are consumed and the remainder stays buffered for the next read.
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $payload = substr($line, 6);

                if ($payload === '[DONE]') {
                    return;
                }

                try {
                    $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    // A malformed event is skipped rather than aborting a stream that is
                    // otherwise producing usable tokens.
                    $this->logger->warning('Skipping malformed SSE payload', ['url' => $url]);
                    continue;
                }

                $delta = $data['choices'][0]['delta']['content'] ?? null;

                if (is_string($delta) && $delta !== '') {
                    $onChunk($delta);
                }
            }
        }
    }
}
