<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Generation;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Generation\LlamaCppStreamingGenerationClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class LlamaCppStreamingGenerationClientTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;
    private LlamaCppStreamingGenerationClient $client;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $configuration = $this->createMock(SmartSearchConfiguration::class);
        $configuration->method('getGenerationServerUrl')->willReturn('http://localhost:8081');
        $configuration->method('getGenerationMaxTokens')->willReturn(512);
        $configuration->method('getGenerationTimeout')->willReturn(300);

        $this->client = new LlamaCppStreamingGenerationClient(
            $this->requestFactory,
            $configuration,
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * Serves the body in fixed-size slices, so read boundaries fall wherever they fall —
     * mid-line and mid-JSON included. The point of the client is its buffering loop, and a mock
     * that returns the whole body from one read() never exercises it.
     */
    private function makeStreamResponse(int $statusCode, string $body, int $sliceSize = 7): ResponseInterface
    {
        $offset = 0;

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')->willReturnCallback(
            static fn(): bool => $offset >= strlen($body),
        );
        $stream->method('read')->willReturnCallback(
            static function () use ($body, &$offset, $sliceSize): string {
                $slice = substr($body, $offset, $sliceSize);
                $offset += strlen($slice);
                return $slice;
            },
        );
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    /**
     * @param string[] $events
     * @return string[]
     */
    private function collectChunks(array $events, int $sliceSize = 7): array
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeStreamResponse(200, implode("\n", $events) . "\n", $sliceSize));

        $chunks = [];
        $this->client->stream(
            [['role' => 'user', 'content' => 'hi']],
            static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
        );

        return $chunks;
    }

    #[Test]
    public function reassemblesDeltasAcrossReadBoundaries(): void
    {
        $chunks = $this->collectChunks([
            'data: {"choices":[{"delta":{"content":"Hello"}}]}',
            'data: {"choices":[{"delta":{"content":" world"}}]}',
            'data: [DONE]',
        ]);

        self::assertSame(['Hello', ' world'], $chunks);
    }

    #[Test]
    public function producesTheSameResultWhateverTheReadSize(): void
    {
        $events = [
            'data: {"choices":[{"delta":{"content":"a"}}]}',
            'data: {"choices":[{"delta":{"content":"b"}}]}',
            'data: [DONE]',
        ];

        // 1 byte at a time is the pathological case for a buffering bug.
        foreach ([1, 3, 7, 64, 4096] as $sliceSize) {
            self::assertSame(['a', 'b'], $this->collectChunks($events, $sliceSize), "slice size {$sliceSize}");
            $this->setUp();
        }
    }

    #[Test]
    public function stopsAtTheDoneSentinelAndIgnoresAnythingAfterIt(): void
    {
        $chunks = $this->collectChunks([
            'data: {"choices":[{"delta":{"content":"kept"}}]}',
            'data: [DONE]',
            'data: {"choices":[{"delta":{"content":"discarded"}}]}',
        ]);

        self::assertSame(['kept'], $chunks);
    }

    #[Test]
    public function ignoresCommentsBlankLinesAndEmptyDeltas(): void
    {
        $chunks = $this->collectChunks([
            ': keep-alive',
            '',
            'data: {"choices":[{"delta":{}}]}',
            'data: {"choices":[{"delta":{"content":""}}]}',
            'data: {"choices":[{"delta":{"content":"real"}}]}',
            'data: [DONE]',
        ]);

        self::assertSame(['real'], $chunks);
    }

    #[Test]
    public function skipsAMalformedEventRatherThanAbandoningTheStream(): void
    {
        $chunks = $this->collectChunks([
            'data: {"choices":[{"delta":{"content":"before"}}]}',
            'data: {not json',
            'data: {"choices":[{"delta":{"content":"after"}}]}',
            'data: [DONE]',
        ]);

        self::assertSame(['before', 'after'], $chunks);
    }

    #[Test]
    public function throwsOnANon200Response(): void
    {
        $this->requestFactory
            ->method('request')
            ->willReturn($this->makeStreamResponse(503, ''));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1_700_003_001);

        $this->client->stream([['role' => 'user', 'content' => 'hi']], static fn() => null);
    }

    #[Test]
    public function requestsStreamingFromGuzzle(): void
    {
        // RequestFactory forwards options straight to Guzzle; without this the body is buffered
        // and nothing actually streams.
        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->with(
                'http://localhost:8081/v1/chat/completions',
                'POST',
                self::callback(static fn(array $options): bool => ($options['stream'] ?? false) === true),
            )
            ->willReturn($this->makeStreamResponse(200, "data: [DONE]\n"));

        $this->client->stream([['role' => 'user', 'content' => 'hi']], static fn() => null);
    }
}
