<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Service;

use BoehmMatthias\SmartSearch\Chunking\ChunkingStrategyInterface;
use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Embedding\EmbeddingClientInterface;
use BoehmMatthias\SmartSearch\Repository\VectorRepository;
use BoehmMatthias\SmartSearch\Reranking\RerankerInterface;
use Psr\Log\LoggerInterface;

class VectorService
{
    public function __construct(
        private readonly EmbeddingClientInterface $embeddingClient,
        private readonly VectorRepository $vectorRepository,
        private readonly SmartSearchConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Embed the given text and store it for the collection/identifier pair.
     * Skips the embedding API call if the text content has not changed since last store.
     *
     * @param array<string, scalar> $metadata Optional metadata stored alongside the vector (e.g. ['sys_language_uid' => 1]).
     */
    public function embedAndStore(string $collection, string|int $identifier, string $text, array $metadata = []): void
    {
        $identifier = (string) $identifier;
        $text = $this->normalise($text);
        $hash = md5($text);

        $stored = $this->vectorRepository->findContentHashAndMetadata($collection, $identifier);

        if ($stored !== null && $stored['hash'] === $hash) {
            // The text is unchanged, so the vector is still valid — but metadata is not part
            // of the hash, and without this it would be write-once: correcting a
            // sys_language_uid or backfilling a new key would silently do nothing, and
            // filters would keep using the stale values.
            if ($stored['metadata'] !== $metadata) {
                $this->vectorRepository->updateMetadata($collection, $identifier, $metadata);
                $this->logger->debug('Updated metadata — content hash unchanged', [
                    'collection' => $collection,
                    'identifier' => $identifier,
                ]);
            } else {
                $this->logger->debug('Skipping embedding — content hash unchanged', [
                    'collection' => $collection,
                    'identifier' => $identifier,
                ]);
            }
            return;
        }

        $vector = $this->embeddingClient->embed($text);
        $this->vectorRepository->upsert($collection, $identifier, $vector, $hash, $metadata);

        $this->logger->debug('Stored embedding', [
            'collection' => $collection,
            'identifier' => $identifier,
            'dimensions' => count($vector),
        ]);
    }

    /**
     * Split text into chunks and embed each chunk separately.
     * Chunk identifiers are derived as "{$identifier}_chunk_{$n}" so they
     * round-trip back to the parent record via a simple explode('_chunk_', ...).
     * Chunks removed since the last call (e.g. because the document got shorter)
     * are deleted automatically.
     *
     * @param ChunkingStrategyInterface $strategy Strategy that decides how to split the text.
     * @param array<string, scalar> $metadata Stored on every chunk, so chunked documents remain
     *                                        reachable through metadata filters.
     */
    public function embedAndStoreChunked(
        string $collection,
        string|int $identifier,
        string $text,
        ChunkingStrategyInterface $strategy,
        array $metadata = [],
    ): void {
        $identifier = (string) $identifier;
        $chunks = $strategy->chunk($text);

        $storedChunkIdentifiers = [];
        foreach ($chunks as $index => $chunk) {
            $chunkIdentifier = $identifier . '_chunk_' . $index;
            $storedChunkIdentifiers[] = $chunkIdentifier;
            $this->embedAndStore($collection, $chunkIdentifier, $chunk, $metadata);
        }

        // Remove stale chunks from a previous version of this document
        $prefix = $identifier . '_chunk_';
        $allInCollection = $this->vectorRepository->findIdentifiersByPrefix($collection, $prefix);
        foreach ($allInCollection as $existing) {
            if (!in_array($existing, $storedChunkIdentifiers, true)) {
                $this->vectorRepository->deleteByIdentifier($collection, $existing);
                $this->logger->debug('Deleted stale chunk', [
                    'collection' => $collection,
                    'identifier' => $existing,
                ]);
            }
        }
    }

    /**
     * The separator between a parent identifier and a chunk index.
     * Consumers explode() on this, so it is part of the public contract.
     */
    public const CHUNK_SEPARATOR = '_chunk_';

    /**
     * Removes a single entry. Mirrors VectorRepository::deleteByIdentifier() so consumers can
     * stay on the service rather than reaching into the repository for deletes alone.
     *
     * Does NOT remove chunks — a document stored with embedAndStoreChunked() has no row under
     * its own identifier. Use deleteChunked() for those.
     */
    public function delete(string $collection, string|int $identifier): void
    {
        $this->vectorRepository->deleteByIdentifier($collection, (string) $identifier);
    }

    /**
     * Removes every chunk belonging to a chunked document.
     *
     * Without this there was no working delete path for chunked content at all: the documented
     * removal call matches one exact identifier, and a chunked document has no row under its own
     * identifier — only "{identifier}_chunk_{n}". Deleting the source record therefore removed
     * nothing, leaving orphaned chunks that kept surfacing in search and resolving to a record
     * that no longer existed.
     *
     * @return int Number of chunks removed.
     */
    public function deleteChunked(string $collection, string|int $identifier): int
    {
        $identifier = (string) $identifier;
        $prefix = $identifier . self::CHUNK_SEPARATOR;
        $deleted = 0;

        foreach ($this->vectorRepository->findIdentifiersByPrefix($collection, $prefix) as $candidate) {
            if (!$this->isOwnChunk($identifier, $candidate)) {
                continue;
            }

            $this->vectorRepository->deleteByIdentifier($collection, $candidate);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * True if $candidate is a chunk of $parent.
     *
     * The prefix query is wider than this: it also returns documents that merely share the
     * prefix, and it is case-insensitive on PostgreSQL and SQLite while the unique index is not.
     * Only "{parent}_chunk_{int}", matched case-sensitively, belongs to $parent.
     */
    private function isOwnChunk(string $parent, string $candidate): bool
    {
        $pattern = '/^' . preg_quote($parent . self::CHUNK_SEPARATOR, '/') . '\d+$/';

        return preg_match($pattern, $candidate) === 1;
    }

    /**
     * Find the most semantically similar entries in the collection.
     *
     * @param array<string, scalar> $metadataFilters Only entries matching ALL filters are considered (e.g. ['sys_language_uid' => 1]).
     * @param bool $collapseChunks Group chunk hits back to their parent document, keeping each
     *        parent's best-scoring chunk, before slicing to $topK. Chunks of one document are
     *        near-duplicates by construction, so without this a single long document can occupy
     *        every slot — turning topK: 5 into five passages from the same source. Identifiers in
     *        the result are then parent identifiers, not chunk identifiers.
     * @return array<array{identifier: string, score: float}> Sorted by score descending
     */
    public function findSimilar(
        string $collection,
        string $query,
        int $topK = 5,
        array $metadataFilters = [],
        bool $collapseChunks = false,
    ): array {
        $all = $this->vectorRepository->findByCollection($collection, $metadataFilters);

        if (empty($all)) {
            return [];
        }

        $queryVector = $this->embeddingClient->embed($query);

        $scored = [];
        foreach ($all as $entry) {
            if (count($entry['vector']) !== count($queryVector)) {
                $this->logger->warning('Vector dimension mismatch — entry skipped', [
                    'collection' => $collection,
                    'identifier' => $entry['identifier'],
                    'stored_dimensions' => count($entry['vector']),
                    'query_dimensions' => count($queryVector),
                ]);
                continue;
            }

            $scored[] = [
                'identifier' => $entry['identifier'],
                'score' => $this->cosineSimilarity($queryVector, $entry['vector']),
            ];
        }

        if ($collapseChunks) {
            $scored = $this->collapseToParents($scored);
        }

        usort($scored, static fn(array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $topK);
    }

    /**
     * Keeps only the best-scoring entry per parent document, mapping chunk identifiers back to
     * their parent. Entries that are not chunks pass through under their own identifier.
     *
     * @param array<array{identifier: string, score: float}> $scored
     * @return array<array{identifier: string, score: float}>
     */
    private function collapseToParents(array $scored): array
    {
        $best = [];

        foreach ($scored as $entry) {
            $parent = explode(self::CHUNK_SEPARATOR, $entry['identifier'])[0];

            if (!isset($best[$parent]) || $entry['score'] > $best[$parent]['score']) {
                $best[$parent] = ['identifier' => $parent, 'score' => $entry['score']];
            }
        }

        return array_values($best);
    }

    /**
     * Retrieve a wider candidate set via vector search, then re-rank the top results
     * using the provided reranker for higher precision.
     *
     * @param int $rerankK How many candidates to retrieve before re-ranking (should be > $topK).
     * @param array<string, scalar> $metadataFilters Applied to the candidate retrieval, exactly as
     *                                               in findSimilar().
     * @return array<array{identifier: string, score: float}> Top $topK results, ordered by the
     *         reranker's judgement of relevance — **not** by descending score. `score` remains the
     *         cosine similarity from vector search, so it stays comparable with findSimilar()'s
     *         output and with semanticThreshold.
     */
    public function findSimilarWithRerank(
        string $collection,
        string $query,
        RerankerInterface $reranker,
        int $topK = 5,
        int $rerankK = 20,
        array $metadataFilters = [],
    ): array {
        $candidates = $this->findSimilar($collection, $query, max($topK, $rerankK), $metadataFilters);

        if (empty($candidates)) {
            return [];
        }

        $reranked = $reranker->rerank($query, $candidates);

        return array_slice($reranked, 0, $topK);
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $length = min(count($a), count($b));
        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    private function normalise(string $text): string
    {
        $text = (string) preg_replace('/\s+/', ' ', trim($text));
        $maxChars = $this->configuration->getEmbeddingContextLength();
        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars);
        }
        return $text;
    }
}
