<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Embedding;

interface EmbeddingClientInterface
{
    /**
     * Embed a single piece of text.
     *
     * Implementations MUST throw on transport failure or an unexpected payload, and MUST NOT
     * return an empty array to signal an error. An empty vector is stored as a zero-length blob
     * and then scores 0.0 against everything, which is indistinguishable from a genuinely
     * unrelated document — a silent failure rather than a reported one.
     *
     * @return float[] Never empty.
     * @throws \RuntimeException on transport failure or an unexpected payload
     */
    public function embed(string $text): array;
}
