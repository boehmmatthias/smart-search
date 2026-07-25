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
     * Implementations must convey the new ranking through **array order only** and leave each
     * entry's `score` untouched. `score` is the cosine similarity produced by vector search, and
     * callers filter on it against `semanticThreshold`; an implementation that replaces it with a
     * rank-derived value silently changes what that threshold means. It follows that the returned
     * array is ordered by relevance, not by descending `score`.
     *
     * @param array<array{identifier: string, score: float}> $candidates Initial ranked results.
     * @return array<array{identifier: string, score: float}> Re-ordered results, best first.
     */
    public function rerank(string $query, array $candidates): array;
}
