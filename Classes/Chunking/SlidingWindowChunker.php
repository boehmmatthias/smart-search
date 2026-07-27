<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Chunking;

/**
 * Splits text into fixed-size windows with a configurable overlap.
 * Tries to break on sentence boundaries ('. ', '? ', '! ') to avoid
 * cutting mid-sentence when a clean break exists near the target size.
 */
class SlidingWindowChunker implements ChunkingStrategyInterface
{
    /**
     * @throws \InvalidArgumentException if the sizes would produce no chunks, discard text, or
     *         fail to advance the window
     */
    public function __construct(
        private readonly int $chunkSize = 800,
        private readonly int $overlapSize = 100,
    ) {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException(
                sprintf('chunkSize must be at least 1 character, got %d.', $chunkSize),
                1_700_009_001,
            );
        }

        // The cursor advances by max($start + 1, $end - $overlapSize), which misbehaves in both
        // directions outside this range. A negative overlap becomes $end + |overlap| and jumps
        // over text that is then never emitted — 300 characters of input came back as 100
        // characters of chunks, silently. An overlap at or above chunkSize lets the max() floor
        // take over, advancing one character per iteration: a 500-character text produced 500
        // chunks, each its own embedding round trip and stored row.
        if ($overlapSize < 0 || $overlapSize >= $chunkSize) {
            throw new \InvalidArgumentException(
                sprintf(
                    'overlapSize must be at least 0 and less than chunkSize (%d), got %d.',
                    $chunkSize,
                    $overlapSize,
                ),
                1_700_009_002,
            );
        }
    }

    public function chunk(string $text): array
    {
        $text = trim($text);
        $length = mb_strlen($text);

        if ($length === 0) {
            return [];
        }

        if ($length <= $this->chunkSize) {
            return [$text];
        }

        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $end = min($start + $this->chunkSize, $length);

            if ($end < $length) {
                // Look back up to 120 chars for a sentence boundary
                $lookback = max($start, $end - 120);
                $window = mb_substr($text, $lookback, $end - $lookback);
                $positions = array_filter([
                    mb_strrpos($window, '. '),
                    mb_strrpos($window, '? '),
                    mb_strrpos($window, '! '),
                ], static fn($p) => $p !== false);
                $lastBreak = empty($positions) ? false : max($positions);

                if ($lastBreak !== false && $lastBreak > 0) {
                    $end = $lookback + $lastBreak + 2; // include the punctuation + space
                }
            }

            $chunk = trim(mb_substr($text, $start, $end - $start));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            $start = max($start + 1, $end - $this->overlapSize);

            if ($start >= $length) {
                break;
            }
        }

        return $chunks;
    }
}
