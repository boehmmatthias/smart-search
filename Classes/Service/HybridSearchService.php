<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Service;

use BoehmMatthias\SmartSearch\Search\KeywordSearchInterface;

/**
 * Fuses semantic and keyword rankings with Reciprocal Rank Fusion.
 *
 * RRF scores each result as weight * 1/(k + rank) and sums across both signals. It compares
 * ranks rather than scores, which is the point: cosine similarity and a keyword relevance score
 * are on unrelated scales and cannot be combined arithmetically. k=60 is the constant from the
 * original RRF paper; it damps the top of each list so a single signal's first place cannot
 * dominate a document that both signals like.
 */
final class HybridSearchService
{
    private const RRF_K = 60;

    /**
     * How much wider than $topK to retrieve from each signal before fusing. A document ranked
     * 8th semantically and 9th by keyword should be able to win overall, which it cannot if
     * both lists were cut at 5.
     */
    private const RETRIEVAL_FACTOR = 4;

    public function __construct(
        private readonly VectorService $vectorService,
        private readonly KeywordSearchInterface $keywordSearch,
    ) {}

    /**
     * @param float $semanticWeight Relative weight of the semantic ranking.
     * @param float $keywordWeight Relative weight of the keyword ranking.
     * @param array<string, scalar> $metadataFilters Applied to the semantic side, as in findSimilar().
     * @param bool $collapseChunks Group chunk hits back to parent documents before fusing.
     * @return array<array{identifier: string, score: float}> Sorted by fused score descending.
     *         The score is an RRF value, not a cosine similarity — do not compare it against
     *         semanticThreshold.
     * @throws \InvalidArgumentException if either weight is negative or both are zero
     */
    public function findSimilar(
        string $collection,
        string $query,
        int $topK = 5,
        float $semanticWeight = 0.7,
        float $keywordWeight = 0.3,
        array $metadataFilters = [],
        bool $collapseChunks = false,
    ): array {
        if ($semanticWeight < 0.0 || $keywordWeight < 0.0) {
            throw new \InvalidArgumentException('RRF weights must not be negative.', 1_700_007_001);
        }

        if ($semanticWeight === 0.0 && $keywordWeight === 0.0) {
            throw new \InvalidArgumentException(
                'At least one of semanticWeight or keywordWeight must be greater than zero.',
                1_700_007_002,
            );
        }

        $retrieveK = $topK * self::RETRIEVAL_FACTOR;

        $semanticResults = $this->vectorService->findSimilar(
            $collection,
            $query,
            $retrieveK,
            $metadataFilters,
            $collapseChunks,
        );

        $scores = [];

        foreach ($semanticResults as $rank => $hit) {
            $identifier = $hit['identifier'];
            $scores[$identifier] = ($scores[$identifier] ?? 0.0)
                + $semanticWeight * (1.0 / (self::RRF_K + $rank + 1));
        }

        // The keyword side is not metadata-filtered — this extension cannot apply filters to a
        // search it does not run. A consumer whose collection mixes languages or tenants must
        // scope its own implementation, or those rows will re-enter through this branch.
        foreach (array_slice($this->keywordSearch->search($collection, $query), 0, $retrieveK) as $rank => $identifier) {
            $scores[$identifier] = ($scores[$identifier] ?? 0.0)
                + $keywordWeight * (1.0 / (self::RRF_K + $rank + 1));
        }

        arsort($scores);

        $results = [];
        foreach (array_slice($scores, 0, $topK, true) as $identifier => $score) {
            $results[] = ['identifier' => (string) $identifier, 'score' => $score];
        }

        return $results;
    }
}
