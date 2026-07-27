<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Search;

/**
 * Plugs a keyword/fulltext search into HybridSearchService.
 *
 * This extension has no keyword search of its own and is not going to grow one — it knows
 * nothing about your records. Implement this and bind it in your Services.yaml:
 *
 *   BoehmMatthias\SmartSearch\Search\KeywordSearchInterface:
 *     alias: MyVendor\MyExt\Search\ArticleKeywordSearch
 *
 * Until you do, NullKeywordSearch is bound and hybrid search degrades to pure semantic search.
 */
interface KeywordSearchInterface
{
    /**
     * Run a keyword search and return matching identifiers, best match first.
     *
     * Identifiers must be drawn from the same namespace as those passed to
     * VectorService::embedAndStore(), or the two rankings cannot be fused.
     *
     * @return string[] Identifiers in ranked order.
     */
    public function search(string $collection, string $query): array;
}
