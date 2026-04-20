<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Service\ModelAvailabilityService;

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

    private function makeResponse(int $statusCode): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        return $response;
    }
}
