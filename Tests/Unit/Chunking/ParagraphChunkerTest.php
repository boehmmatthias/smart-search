<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Chunking;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use BoehmMatthias\SmartSearch\Chunking\ParagraphChunker;

final class ParagraphChunkerTest extends TestCase
{
    #[Test]
    public function splitsSingleParagraphIntoOneChunk(): void
    {
        $chunker = new ParagraphChunker();
        $result = $chunker->chunk('Hello world. This is a single paragraph.');

        self::assertCount(1, $result);
        self::assertSame('Hello world. This is a single paragraph.', $result[0]);
    }

    #[Test]
    public function splitsOnBlankLines(): void
    {
        $chunker = new ParagraphChunker(minChunkSize: 1, maxChunkSize: 10000);
        $result = $chunker->chunk("First paragraph.\n\nSecond paragraph.");

        self::assertCount(2, $result);
        self::assertSame('First paragraph.', $result[0]);
        self::assertSame('Second paragraph.', $result[1]);
    }

    #[Test]
    public function mergesSmallParagraphsUntilMaxChunkSize(): void
    {
        $chunker = new ParagraphChunker(minChunkSize: 1, maxChunkSize: 50);
        $text = "Short.\n\nAlso short.\n\nThis third paragraph is deliberately long enough to force a new chunk here.";
        $result = $chunker->chunk($text);

        self::assertGreaterThanOrEqual(2, count($result));
    }

    #[Test]
    public function returnsEmptyArrayForEmptyInput(): void
    {
        $chunker = new ParagraphChunker();
        self::assertSame([], $chunker->chunk(''));
        self::assertSame([], $chunker->chunk('   '));
    }

    #[Test]
    public function mergesTooSmallTrailingFragmentIntoLastChunk(): void
    {
        // minChunkSize = 100; trailing fragment is only 3 chars → must be merged
        $chunker = new ParagraphChunker(minChunkSize: 100, maxChunkSize: 10000);
        $text = str_repeat('A', 200) . "\n\n" . 'Hi.';
        $result = $chunker->chunk($text);

        self::assertCount(1, $result);
        self::assertStringContainsString('Hi.', $result[0]);
    }
}
