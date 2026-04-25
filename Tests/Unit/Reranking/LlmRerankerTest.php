<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Reranking;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use BoehmMatthias\SmartSearch\Generation\GenerationClientInterface;
use BoehmMatthias\SmartSearch\Reranking\LlmReranker;

final class LlmRerankerTest extends TestCase
{
    private GenerationClientInterface&MockObject $client;
    private LlmReranker $reranker;

    protected function setUp(): void
    {
        $this->client = $this->createMock(GenerationClientInterface::class);
        $this->reranker = new LlmReranker($this->client, $this->createMock(LoggerInterface::class));
    }

    #[Test]
    public function reranksAccordingToLlmOrder(): void
    {
        $this->client->method('complete')->willReturn('["3", "1", "2"]');

        $candidates = [
            ['identifier' => '1', 'score' => 0.9],
            ['identifier' => '2', 'score' => 0.8],
            ['identifier' => '3', 'score' => 0.7],
        ];

        $result = $this->reranker->rerank('query', $candidates);

        self::assertSame('3', $result[0]['identifier']);
        self::assertSame('1', $result[1]['identifier']);
        self::assertSame('2', $result[2]['identifier']);
    }

    #[Test]
    public function returnsSingleCandidateUnchanged(): void
    {
        $candidates = [['identifier' => 'X', 'score' => 0.5]];
        $this->client->expects(self::never())->method('complete');

        $result = $this->reranker->rerank('query', $candidates);

        self::assertSame($candidates, $result);
    }

    #[Test]
    public function fallsBackToOriginalOrderOnLlmFailure(): void
    {
        $this->client->method('complete')->willThrowException(new \RuntimeException('timeout'));

        $candidates = [
            ['identifier' => 'A', 'score' => 0.9],
            ['identifier' => 'B', 'score' => 0.8],
        ];

        $result = $this->reranker->rerank('query', $candidates);

        self::assertSame($candidates, $result);
    }

    #[Test]
    public function appendsOmittedCandidatesAtEnd(): void
    {
        // LLM only returns one of two candidates
        $this->client->method('complete')->willReturn('["A"]');

        $candidates = [
            ['identifier' => 'A', 'score' => 0.9],
            ['identifier' => 'B', 'score' => 0.8],
        ];

        $result = $this->reranker->rerank('query', $candidates);

        self::assertCount(2, $result);
        self::assertSame('A', $result[0]['identifier']);
        self::assertSame('B', $result[1]['identifier']);
    }
}
