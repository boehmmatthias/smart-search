<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Command;

/**
 * Implement this in your extension and tag the service with `smartsearch.reindex_handler` to
 * make it discoverable by `smartsearch:reindex`.
 *
 * This extension cannot reindex anything on its own — it does not know your records or how to
 * turn them into text. The handler is where that knowledge lives.
 *
 *   MyVendor\MyExt\Search\ArticleReindexHandler:
 *     tags:
 *       - name: smartsearch.reindex_handler
 */
interface ReindexCommandInterface
{
    /**
     * Human-readable label shown in the command output, e.g. "news articles".
     */
    public function getLabel(): string;

    /**
     * The collection this handler writes to, e.g. "myext_articles".
     */
    public function getCollection(): string;

    /**
     * Perform the reindex, calling VectorService::embedAndStore() per record.
     *
     * @return int Number of records processed.
     */
    public function reindex(): int;
}
