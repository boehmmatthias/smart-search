<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Search;

/**
 * Default KeywordSearchInterface binding: finds nothing.
 *
 * It exists so the container can resolve HybridSearchService out of the box. Without a default,
 * an unbound interface makes the whole container fail to compile — which takes down the site,
 * not just this extension — and the failure surfaces the moment anything references the service.
 *
 * With this bound, hybrid search degrades to pure semantic search until a consumer supplies a
 * real implementation. Returning nothing is the honest degradation: the fused ranking is then
 * exactly the semantic ranking, rather than a keyword signal that is quietly wrong.
 */
final class NullKeywordSearch implements KeywordSearchInterface
{
    /**
     * @return string[]
     */
    public function search(string $collection, string $query): array
    {
        return [];
    }
}
