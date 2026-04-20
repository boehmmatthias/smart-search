<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Generation;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;

class LlamaCppGenerationClient implements GenerationClientInterface
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly SmartSearchConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<array{role: string, content: string}> $messages
     * @throws \RuntimeException if the generation server returns a non-200 response or an unexpected payload
     */
    public function complete(array $messages): string
    {
        $url = $this->configuration->getGenerationServerUrl() . '/v1/chat/completions';

        $response = $this->requestFactory->request(
            $url,
            'POST',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(
                    [
                        'messages' => $messages,
                        'max_tokens' => $this->configuration->getGenerationMaxTokens(),
                        'stream' => false,
                    ],
                    JSON_THROW_ON_ERROR
                ),
                'timeout' => $this->configuration->getGenerationTimeout(),
                'http_errors' => false,
            ]
        );

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            $body = (string) $response->getBody();
            $this->logger->error('Generation server returned unexpected status code', [
                'url' => $url,
                'status_code' => $statusCode,
                'response_body' => mb_substr($body, 0, 500),
            ]);
            throw new \RuntimeException(
                sprintf('Generation server at "%s" returned HTTP %d.', $url, $statusCode),
                1_700_000_003
            );
        }

        $data = json_decode(
            (string) $response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!isset($data['choices'][0]['message']['content']) || !is_string($data['choices'][0]['message']['content'])) {
            $this->logger->error('Generation server response has unexpected structure', [
                'url' => $url,
                'response_keys' => is_array($data) ? array_keys($data) : gettype($data),
            ]);
            throw new \RuntimeException(
                sprintf('Generation server at "%s" returned an unexpected response structure.', $url),
                1_700_000_004
            );
        }

        return $data['choices'][0]['message']['content'];
    }
}
