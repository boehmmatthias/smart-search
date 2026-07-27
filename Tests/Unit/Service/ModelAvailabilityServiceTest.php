<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Service;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Service\ModelAvailabilityService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class ModelAvailabilityServiceTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;
    private SmartSearchConfiguration&MockObject $configuration;
    private LoggerInterface&MockObject $logger;
    private ModelAvailabilityService $service;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->configuration = $this->createMock(SmartSearchConfiguration::class);
        $this->configuration->method('getEmbeddingServerUrl')->willReturn('http://localhost:8080');
        $this->configuration->method('getGenerationServerUrl')->willReturn('http://localhost:8081');
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ModelAvailabilityService(
            $this->requestFactory,
            $this->configuration,
            $this->logger,
        );
    }

    #[Test]
    public function embeddingServerIsAvailableWhenHealthReturns200(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(200));

        self::assertTrue($this->service->isEmbeddingServerAvailable());
    }

    #[Test]
    public function embeddingServerIsUnavailableWhenHealthReturns500(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(500));

        self::assertFalse($this->service->isEmbeddingServerAvailable());
    }

    #[Test]
    public function embeddingServerIsUnavailableOnNetworkException(): void
    {
        $this->requestFactory
            ->method('request')
            ->willThrowException(new \RuntimeException('Connection refused'));

        self::assertFalse($this->service->isEmbeddingServerAvailable());
    }

    #[Test]
    public function generationServerIsAvailableWhenHealthReturns200(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(200));

        self::assertTrue($this->service->isGenerationServerAvailable());
    }

    #[Test]
    public function generationServerIsUnavailableWhenHealthReturns503(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(503));

        self::assertFalse($this->service->isGenerationServerAvailable());
    }

    #[Test]
    public function resultIsCachedAfterFirstCall(): void
    {
        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($this->makeResponse(200));

        $this->service->isEmbeddingServerAvailable();
        $this->service->isEmbeddingServerAvailable(); // second call must not trigger another request
    }

    #[Test]
    public function ollamaAvailabilityProbesTheOllamaServerNotTheLlamaCppUrl(): void
    {
        // The probe used to hit {embeddingServerUrl}/health whatever the configured provider,
        // so an Ollama install reported its embedding backend unavailable while it was working
        // — and the documented use of this service is to hide search and RAG features.
        $this->configuration->method('getEmbeddingProvider')->willReturn('ollama');
        $this->configuration->method('getOllamaServerUrl')->willReturn('http://ollama.example:11434');

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->with('http://ollama.example:11434/api/tags', 'GET', self::anything())
            ->willReturn($this->makeResponse(200));

        self::assertTrue($this->service->isEmbeddingServerAvailable());
    }

    #[Test]
    public function ollamaGenerationAvailabilityProbesTheOllamaServerToo(): void
    {
        $this->configuration->method('getGenerationProvider')->willReturn('ollama');
        $this->configuration->method('getOllamaServerUrl')->willReturn('http://ollama.example:11434/');

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->with('http://ollama.example:11434/api/tags', 'GET', self::anything())
            ->willReturn($this->makeResponse(200));

        self::assertTrue($this->service->isGenerationServerAvailable());
    }

    #[Test]
    public function openAiAvailabilityIsDecidedByTheApiKeyWithoutANetworkCall(): void
    {
        // OpenAI exposes no free health endpoint — the cheapest check is a billable API call.
        // Configuration is the only thing worth reporting on, and it is the only thing that
        // actually fails locally.
        $this->configuration->method('getEmbeddingProvider')->willReturn('openai');
        $this->configuration->method('getOpenAiApiKey')->willReturn('sk-test');

        $this->requestFactory->expects(self::never())->method('request');

        self::assertTrue($this->service->isEmbeddingServerAvailable());
    }

    #[Test]
    public function openAiIsUnavailableWhenNoApiKeyIsConfigured(): void
    {
        $this->configuration->method('getGenerationProvider')->willReturn('openai');
        $this->configuration->method('getOpenAiApiKey')->willReturn('');

        $this->requestFactory->expects(self::never())->method('request');

        self::assertFalse($this->service->isGenerationServerAvailable());
    }

    #[Test]
    public function theTwoSidesAreProbedIndependently(): void
    {
        // Providers are chosen independently, so embedding on llama.cpp with generation on
        // OpenAI is a normal arrangement and each side must answer for its own backend.
        $this->configuration->method('getEmbeddingProvider')->willReturn('llamacpp');
        $this->configuration->method('getGenerationProvider')->willReturn('openai');
        $this->configuration->method('getOpenAiApiKey')->willReturn('sk-test');

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->with('http://localhost:8080/health', 'GET', self::anything())
            ->willReturn($this->makeResponse(200));

        self::assertTrue($this->service->isEmbeddingServerAvailable());
        self::assertTrue($this->service->isGenerationServerAvailable());
    }

    private function makeResponse(int $statusCode): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        return $response;
    }
}
