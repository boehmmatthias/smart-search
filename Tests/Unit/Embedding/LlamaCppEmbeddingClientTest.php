<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Embedding;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Embedding\LlamaCppEmbeddingClient;

final class LlamaCppEmbeddingClientTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;
    private SmartSearchConfiguration&MockObject $configuration;
    private LoggerInterface&MockObject $logger;
    private LlamaCppEmbeddingClient $client;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->configuration = $this->createMock(SmartSearchConfiguration::class);
        $this->configuration->method('getEmbeddingServerUrl')->willReturn('http://localhost:8080');
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->client = new LlamaCppEmbeddingClient(
            $this->requestFactory,
            $this->configuration,
            $this->logger,
        );
    }

    #[Test]
    public function embedReturnsFloatArrayOnSuccessfulResponse(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $payload = json_encode([[
            'embedding' => [$embedding],
        ]]);

        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(200, $payload));

        $result = $this->client->embed('hello world');

        self::assertSame($embedding, $result);
    }

    #[Test]
    public function embedRetriesAndHalvesTextOn400(): void
    {
        $embedding = [0.5, 0.6];
        $successPayload = json_encode([[
            'embedding' => [[$embedding[0], $embedding[1]]],
        ]]);

        $this->requestFactory
            ->expects(self::exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->makeResponse(400, '{"error":"too long"}'),
                $this->makeResponse(200, $successPayload),
            );

        $this->logger->expects(self::atLeastOnce())->method('warning');

        $result = $this->client->embed('some long text');

        self::assertSame([$embedding[0], $embedding[1]], $result);
    }

    #[Test]
    public function embedThrowsOnNon200FinalResponse(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(500, '{"error":"internal server error"}'));

        $this->logger->expects(self::once())->method('error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1_700_000_001);

        $this->client->embed('test');
    }

    #[Test]
    public function embedThrowsOnMissingEmbeddingKeyInResponse(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(200, json_encode([['no_embedding_here' => []]])));

        $this->logger->expects(self::once())->method('error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1_700_000_002);

        $this->client->embed('test');
    }

    #[Test]
    public function embedThrowsJsonExceptionOnMalformedBody(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(200, 'not-json{'));

        $this->expectException(\JsonException::class);

        $this->client->embed('test');
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
