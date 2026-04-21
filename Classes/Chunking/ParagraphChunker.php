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
            $candidate = $current !== '' ? $current . "\n\n" . $paragraph : $paragraph;

            if (mb_strlen($candidate) > $this->maxChunkSize && $current !== '') {
                $chunks[] = $current;
                $current = $paragraph;
            } else {
                $current = $candidate;
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
