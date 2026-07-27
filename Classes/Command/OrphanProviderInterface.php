<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Command;

/**
 * Implement this in your extension and tag the service with `smartsearch.orphan_provider` to
 * teach `smartsearch:cleanup` which identifiers are still live for your collection.
 *
 *   MyVendor\MyExt\Search\ArticleOrphanProvider:
 *     tags:
 *       - name: smartsearch.orphan_provider
 */
interface OrphanProviderInterface
{
    /**
     * The collection this provider manages, e.g. "myext_articles".
     */
    public function getCollection(): string;

    /**
     * Identifiers that currently exist in the source data. Vectors for identifiers NOT in this
     * list are deleted, so returning a partial list deletes live content.
     *
     * Return an empty array only if the source really is empty — see
     * VectorRepository::deleteOrphans(), which requires that to be stated explicitly.
     *
     * @return string[]
     */
    public function getLiveIdentifiers(): array;
}
