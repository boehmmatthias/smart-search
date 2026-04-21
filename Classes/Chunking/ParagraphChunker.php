<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Chunking;

/**
 * Splits text on blank lines (paragraphs). Chunks smaller than $minChunkSize
 * are merged with the next paragraph to avoid indexing tiny fragments.
 */
class ParagraphChunker implements ChunkingStrategyInterface
{
    public function __construct(
        private readonly int $minChunkSize = 100,
        private readonly int $maxChunkSize = 800,
    ) {}

    public function chunk(string $text): array
    {
        $paragraphs = preg_split('/\n{2,}/', trim($text)) ?: [];
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs)));

        if (empty($paragraphs)) {
            return [];
        }

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            if ($current === '') {
                $current = $paragraph;
                continue;
            }

            if (mb_strlen($current) < $this->minChunkSize) {
                // Current chunk is too small to stand alone — try to absorb the next paragraph
                $candidate = $current . "\n\n" . $paragraph;
                if (mb_strlen($candidate) <= $this->maxChunkSize) {
                    $current = $candidate;
                } else {
                    $chunks[] = $current;
                    $current = $paragraph;
                }
            } else {
                // Current chunk is substantial — emit it and start a new one
                $chunks[] = $current;
                $current = $paragraph;
            }
        }

        if ($current !== '') {
            // Merge a tiny trailing fragment into the last chunk if possible
            if (mb_strlen($current) < $this->minChunkSize && !empty($chunks)) {
                $chunks[count($chunks) - 1] .= "\n\n" . $current;
            } else {
                $chunks[] = $current;
            }
        }

        return $chunks;
    }
}
