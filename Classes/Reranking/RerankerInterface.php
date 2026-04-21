<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Reranking;

/**
 * A re-ranker takes an initial set of candidates (from vector search) and
 * returns them reordered by a more precise relevance signal.
 */
interface RerankerInterface
{
    /**
     * Re-rank the candidates for the given query and return them ordered best-first.
     *
     * @param array<array{identifier: string, score: float}> $candidates Initial ranked results.
     * @return array<array{identifier: string, score: float}> Re-ordered results.
     */
    public function rerank(string $query, array $candidates): array;
}
