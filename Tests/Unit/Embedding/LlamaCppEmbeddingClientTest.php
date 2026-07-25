<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Embedding;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Embedding\LlamaCppEmbeddingClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

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
        ]], JSON_THROW_ON_ERROR);

        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(200, $payload));

        $result = $this->client->embed('hello world');

        self::assertSame($embedding, $result);
    }

    #[Test]
    public function embedThrowsOn400InsteadOfEmbeddingATruncatedDocument(): void
    {
        // Previously this halved the text and retried up to four times, returning a vector for
        // as little as an eighth of the document. The caller hashes the full text before
        // calling, so that partial vector was stored against the full-text hash and the row
        // could never be repaired.
        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($this->makeResponse(400, '{"error":"too long"}'));

        $this->logger->expects(self::atLeastOnce())->method('error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1_700_000_003);

        $this->client->embed('some long text');
    }

    #[Test]
    public function embedThrowsOnAnEmptyEmbeddingArray(): void
    {
        // [{"embedding":[[]]}] satisfies both isset() and is_array(). Returning [] from it
        // stores a zero-length vector that later scores 0.0 against everything.
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeResponse(200, (string) json_encode([['embedding' => [[]]]])));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1_700_000_002);

        $this->client->embed('hello world');
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
            ->willReturn($this->makeResponse(200, json_encode([['no_embedding_here' => []]], JSON_THROW_ON_ERROR)));

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
