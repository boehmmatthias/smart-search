<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Service;

use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;
use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;

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
        if ($this->embeddingAvailable === null) {
            $this->embeddingAvailable = $this->checkUrl(
                $this->configuration->getEmbeddingServerUrl() . '/health',
                'embedding'
            );
        }

        return $this->embeddingAvailable;
    }

    public function isGenerationServerAvailable(): bool
    {
        if ($this->generationAvailable === null) {
            $this->generationAvailable = $this->checkUrl(
                $this->configuration->getGenerationServerUrl() . '/health',
                'generation'
            );
        }

        return $this->generationAvailable;
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
