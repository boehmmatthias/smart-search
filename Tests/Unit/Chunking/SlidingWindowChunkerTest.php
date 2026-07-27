<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Chunking;

use BoehmMatthias\SmartSearch\Chunking\SlidingWindowChunker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SlidingWindowChunkerTest extends TestCase
{
    #[Test]
    public function shortTextProducesSingleChunk(): void
    {
        $chunker = new SlidingWindowChunker(chunkSize: 800, overlapSize: 100);
        $result = $chunker->chunk('Short text.');

        self::assertCount(1, $result);
        self::assertSame('Short text.', $result[0]);
    }

    #[Test]
    public function returnsEmptyArrayForEmptyInput(): void
    {
        $chunker = new SlidingWindowChunker();
        self::assertSame([], $chunker->chunk(''));
        self::assertSame([], $chunker->chunk('   '));
    }

    #[Test]
    public function longTextProducesMultipleChunks(): void
    {
        $chunker = new SlidingWindowChunker(chunkSize: 100, overlapSize: 20);
        $text = str_repeat('Hello world. ', 30);
        $result = $chunker->chunk($text);

        self::assertGreaterThan(1, count($result));
    }

    #[Test]
    public function overlappingChunksShareContent(): void
    {
        $chunker = new SlidingWindowChunker(chunkSize: 50, overlapSize: 20);
        $text = str_repeat('abcdefghij', 20); // 200 chars, no sentence boundaries
        $chunks = $chunker->chunk($text);

        self::assertGreaterThan(1, count($chunks));
        // The end of chunk N should appear in chunk N+1 due to overlap
        $endOfFirst = mb_substr($chunks[0], -10);
        self::assertStringContainsString($endOfFirst, $chunks[1]);
    }

    #[Test]
    public function rejectsAnOverlapThatWouldDiscardText(): void
    {
        // The cursor advances by max($start + 1, $end - $overlapSize). A negative overlap turns
        // that into $end + |overlap|, jumping past text that is then never emitted — 300 chars
        // of input came back as 100 chars of chunks, with no error.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1_700_009_002);

        new SlidingWindowChunker(chunkSize: 50, overlapSize: -100);
    }

    #[Test]
    public function rejectsAnOverlapThatWouldNotAdvanceTheWindow(): void
    {
        // With overlapSize >= chunkSize the max() floor takes over and the window advances one
        // character per iteration: a 500-char text produced 500 chunks, which is 500 sequential
        // embedding round trips and 500 stored rows for half a kilobyte of input.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1_700_009_002);

        new SlidingWindowChunker(chunkSize: 50, overlapSize: 50);
    }

    #[Test]
    public function rejectsANonPositiveChunkSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1_700_009_001);

        new SlidingWindowChunker(chunkSize: 0, overlapSize: 0);
    }

    #[Test]
    public function aZeroOverlapIsAllowed(): void
    {
        $chunker = new SlidingWindowChunker(chunkSize: 50, overlapSize: 0);
        $text = str_repeat('abcdefghij', 20);

        self::assertSame($text, implode('', $chunker->chunk($text)));
    }

    #[Test]
    public function allChunksAreNonEmpty(): void
    {
        $chunker = new SlidingWindowChunker(chunkSize: 50, overlapSize: 10);
        $text = str_repeat('Some sentence. ', 40);
        $chunks = $chunker->chunk($text);

        foreach ($chunks as $chunk) {
            self::assertNotSame('', $chunk);
        }
    }
}
