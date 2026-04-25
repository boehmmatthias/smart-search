<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Service;

use Psr\Log\LoggerInterface;
use BoehmMatthias\SmartSearch\Chunking\ChunkingStrategyInterface;
use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Embedding\EmbeddingClientInterface;
use BoehmMatthias\SmartSearch\Repository\VectorRepository;
use BoehmMatthias\SmartSearch\Reranking\RerankerInterface;

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

        if ($this->vectorRepository->findContentHash($collection, $identifier) === $hash) {
            $this->logger->debug('Skipping embedding — content hash unchanged', [
                'collection' => $collection,
                'identifier' => $identifier,
            ]);
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
     */
    public function embedAndStoreChunked(
        string $collection,
        string|int $identifier,
        string $text,
        ChunkingStrategyInterface $strategy,
    ): void {
        $identifier = (string) $identifier;
        $chunks = $strategy->chunk($text);

        $storedChunkIdentifiers = [];
        foreach ($chunks as $index => $chunk) {
            $chunkIdentifier = $identifier . '_chunk_' . $index;
            $storedChunkIdentifiers[] = $chunkIdentifier;
            $this->embedAndStore($collection, $chunkIdentifier, $chunk);
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
     * Find the most semantically similar entries in the collection.
     *
     * @param array<string, scalar> $metadataFilters Only entries matching ALL filters are considered (e.g. ['sys_language_uid' => 1]).
     * @return array<array{identifier: string, score: float}> Sorted by score descending
     */
    public function findSimilar(string $collection, string $query, int $topK = 5, array $metadataFilters = []): array
    {
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

        usort($scored, static fn(array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $topK);
    }

    /**
     * Retrieve a wider candidate set via vector search, then re-rank the top results
     * using the provided reranker for higher precision.
     *
     * @param int $rerankK How many candidates to retrieve before re-ranking (should be > $topK).
     * @return array<array{identifier: string, score: float}> Top $topK results after re-ranking.
     */
    public function findSimilarWithRerank(
        string $collection,
        string $query,
        int $topK = 5,
        int $rerankK = 20,
        RerankerInterface $reranker,
    ): array {
        $candidates = $this->findSimilar($collection, $query, max($topK, $rerankK));

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
