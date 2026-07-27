<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Generation;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Chat completion client for the OpenAI /v1/chat/completions endpoint.
 */
final class OpenAiGenerationClient implements GenerationClientInterface
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly SmartSearchConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<array{role: string, content: string}> $messages
     * @throws \RuntimeException if no API key is configured, or on transport failure or an
     *         unexpected payload
     */
    public function complete(array $messages): string
    {
        $apiKey = $this->configuration->getOpenAiApiKey();

        if ($apiKey === '') {
            throw new \RuntimeException(
                'No OpenAI API key configured. Set openAiApiKey in the extension configuration.',
                1_700_008_006,
            );
        }

        $response = $this->requestFactory->request(self::ENDPOINT, 'POST', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
            ],
            'body' => json_encode([
                'model' => $this->configuration->getOpenAiGenerationModel(),
                'messages' => $messages,
                'max_tokens' => $this->configuration->getGenerationMaxTokens(),
            ], JSON_THROW_ON_ERROR),
            'timeout' => $this->configuration->getGenerationTimeout(),
            'http_errors' => false,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $this->logger->error('OpenAI chat API returned unexpected status code', [
                'status_code' => $statusCode,
                'response_body' => mb_substr((string) $response->getBody(), 0, 500),
            ]);
            throw new \RuntimeException(
                sprintf('OpenAI chat API returned HTTP %d.', $statusCode),
                1_700_008_007,
            );
        }

        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $content = is_array($data) ? ($data['choices'][0]['message']['content'] ?? null) : null;

        if (!is_string($content)) {
            $this->logger->error('OpenAI chat API response has unexpected structure', [
                'response_keys' => is_array($data) ? array_keys($data) : gettype($data),
            ]);
            throw new \RuntimeException(
                'OpenAI chat API returned an unexpected response structure.',
                1_700_008_008,
            );
        }

        return $content;
    }
}
