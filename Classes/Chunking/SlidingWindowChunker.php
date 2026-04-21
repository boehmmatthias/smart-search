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
    public function __construct(
        private readonly int $chunkSize = 800,
        private readonly int $overlapSize = 100,
    ) {}

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
                $lastBreak = max(
                    mb_strrpos($window, '. '),
                    mb_strrpos($window, '? '),
                    mb_strrpos($window, '! '),
                );

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
