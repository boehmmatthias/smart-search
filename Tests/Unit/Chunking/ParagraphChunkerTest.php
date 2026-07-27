<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Chunking;

use BoehmMatthias\SmartSearch\Chunking\ParagraphChunker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    public function splitsOnBlankLinesRegardlessOfLineEndingStyle(): void
    {
        // A CRLF blank line is "\r\n\r\n", whose two \n are not adjacent, so a /\n{2,}/ split
        // never fired on it. CRLF reaches this routinely — textarea and RTE content submitted
        // from a Windows browser, and imported documents. The failure was silent and lossy: the
        // whole document became one chunk, which VectorService::normalise() then truncated at
        // embeddingContextLength, dropping everything past it from the index without a warning.
        $chunker = new ParagraphChunker(minChunkSize: 1, maxChunkSize: 10000);
        $expected = ['First paragraph.', 'Second paragraph.', 'Third paragraph.'];

        self::assertSame($expected, $chunker->chunk("First paragraph.\n\nSecond paragraph.\n\nThird paragraph."));
        self::assertSame($expected, $chunker->chunk("First paragraph.\r\n\r\nSecond paragraph.\r\n\r\nThird paragraph."));
        self::assertSame($expected, $chunker->chunk("First paragraph.\r\rSecond paragraph.\r\rThird paragraph."));
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
