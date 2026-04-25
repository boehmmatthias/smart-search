<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Chunking;

interface ChunkingStrategyInterface
{
    /**
     * Split text into chunks suitable for embedding.
     *
     * @return string[] Non-empty chunks in document order.
     */
    public function chunk(string $text): array;
}
