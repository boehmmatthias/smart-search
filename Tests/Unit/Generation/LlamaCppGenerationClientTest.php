<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Generation\LlamaCppGenerationClient;

final class LlamaCppGenerationClientTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;
    private SmartSearchConfiguration&MockObject $configuration;
    private LoggerInterface&MockObject $logger;
    private LlamaCppGenerationClient $client;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->configuration = $this->createMock(SmartSearchConfiguration::class);
        $this->configuration->method('getGenerationServerUrl')->willReturn('http://localhost:8081');
        $this->configuration->method('getGenerationMaxTokens')->willReturn(512);
        $this->configuration->method('getGenerationTimeout')->willReturn(30);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->client = new LlamaCppGenerationClient(
            $this->requestFactory,
            $this->configuration,
            $this->logger,
        );
    }

    #[Test]
    public function completeReturnsContentStringOnSuccess(): void
    {
        $payload = json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Paris is the capital of France.'],
            ]],
        ]);

        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(200, $payload));

        $result = $this->client->complete([['role' => 'user', 'content' => 'What is the capital of France?']]);

        self::assertSame('Paris is the capital of France.', $result);
    }

    #[Test]
    public function completeThrowsOnNon200Response(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(503, '{"error":"service unavailable"}'));

        $this->logger->expects(self::once())->method('error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1_700_000_003);

        $this->client->complete([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function completeThrowsOnMissingChoicesKey(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(200, json_encode(['result' => 'unexpected'])));

        $this->logger->expects(self::once())->method('error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1_700_000_004);

        $this->client->complete([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function completeThrowsJsonExceptionOnMalformedBody(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(200, 'not-json{'));

        $this->expectException(\JsonException::class);

        $this->client->complete([['role' => 'user', 'content' => 'test']]);
    }

    private function makeResponse(int $statusCode, string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
