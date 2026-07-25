<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use BoehmMatthias\SmartSearch\Chunking\ChunkingStrategyInterface;
use BoehmMatthias\SmartSearch\Reranking\RerankerInterface;
use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Embedding\EmbeddingClientInterface;
use BoehmMatthias\SmartSearch\Repository\VectorRepository;
use BoehmMatthias\SmartSearch\Service\VectorService;

final class VectorServiceTest extends TestCase
{
    private EmbeddingClientInterface&MockObject $embeddingClient;
    private VectorRepository&MockObject $vectorRepository;
    private SmartSearchConfiguration&MockObject $configuration;
    private VectorService $service;

    protected function setUp(): void
    {
        $this->embeddingClient = $this->createMock(EmbeddingClientInterface::class);
        $this->vectorRepository = $this->createMock(VectorRepository::class);
        $this->configuration = $this->createMock(SmartSearchConfiguration::class);
        $this->configuration->method('getEmbeddingContextLength')->willReturn(6000);

        $this->service = new VectorService(
            $this->embeddingClient,
            $this->vectorRepository,
            $this->configuration,
            $this->createMock(LoggerInterface::class),
        );
    }

    // --- cosineSimilarity ---

    #[Test]
    public function cosineSimilarityOfIdenticalVectorsIsOne(): void
    {
        $vector = [0.5, 0.5, 0.5, 0.5];

        $result = $this->service->cosineSimilarity($vector, $vector);

        self::assertEqualsWithDelta(1.0, $result, 0.0001);
    }

    #[Test]
    public function cosineSimilarityOfOrthogonalVectorsIsZero(): void
    {
        $a = [1.0, 0.0];
        $b = [0.0, 1.0];

        $result = $this->service->cosineSimilarity($a, $b);

        self::assertEqualsWithDelta(0.0, $result, 0.0001);
    }

    #[Test]
    public function cosineSimilarityOfZeroVectorIsZero(): void
    {
        $result = $this->service->cosineSimilarity([0.0, 0.0], [1.0, 1.0]);

        self::assertEqualsWithDelta(0.0, $result, 0.0001);
    }

    // --- embedAndStore ---

    #[Test]
    public function embedAndStoreSkipsEmbeddingWhenHashMatches(): void
    {
        $text = 'Hello World';
        $hash = md5($text);

        $this->vectorRepository
            ->method('findContentHashAndMetadata')
            ->with('my-collection', '1')
            ->willReturn(['hash' => $hash, 'metadata' => []]);

        $this->embeddingClient->expects(self::never())->method('embed');
        $this->vectorRepository->expects(self::never())->method('upsert');

        $this->service->embedAndStore('my-collection', 1, $text);
    }

    #[Test]
    public function embedAndStoreCallsEmbedWhenHashDiffers(): void
    {
        $this->vectorRepository
            ->method('findContentHashAndMetadata')
            ->willReturn(['hash' => 'old_hash', 'metadata' => []]);

        $this->embeddingClient
            ->expects(self::once())
            ->method('embed')
            ->willReturn([0.1, 0.2]);

        // Asserting the arguments, not merely that upsert happened. The argument-blind
        // version of this test is why chunked storage could silently drop metadata.
        $this->vectorRepository
            ->expects(self::once())
            ->method('upsert')
            ->with('my-collection', '1', [0.1, 0.2], md5('Some text'), ['site' => 'main']);

        $this->service->embedAndStore('my-collection', 1, 'Some text', ['site' => 'main']);
    }

    #[Test]
    public function embedAndStoreNormalisesWhitespace(): void
    {
        $this->vectorRepository->method('findContentHash')->willReturn(null);

        $this->embeddingClient
            ->expects(self::once())
            ->method('embed')
            ->with('foo bar baz')
            ->willReturn([0.1]);

        $this->service->embedAndStore('col', '1', "foo  bar\n\nbaz");
    }

    // --- findSimilar ---

    #[Test]
    public function findSimilarReturnsTopKResultsSortedByScore(): void
    {
        $this->embeddingClient
            ->method('embed')
            ->willReturn([1.0, 0.0]);

        $this->vectorRepository
            ->method('findByCollection')
            ->willReturn([
                ['identifier' => '1', 'vector' => [1.0, 0.0]],  // score = 1.0
                ['identifier' => '2', 'vector' => [0.0, 1.0]],  // score = 0.0
                ['identifier' => '3', 'vector' => [0.7, 0.7]],  // score ~= 0.7
            ]);

        $results = $this->service->findSimilar('col', 'query', 2);

        self::assertCount(2, $results);
        self::assertSame('1', $results[0]['identifier']);
        self::assertSame('3', $results[1]['identifier']);
    }

    #[Test]
    public function findSimilarReturnsEmptyArrayWhenNoVectorsExist(): void
    {
        $this->vectorRepository->method('findByCollection')->willReturn([]);

        $results = $this->service->findSimilar('col', 'query');

        self::assertSame([], $results);
    }

    // --- findSimilarWithRerank ---

    #[Test]
    public function findSimilarWithRerankReturnsEmptyArrayWhenNoVectorsExist(): void
    {
        $reranker = $this->createMock(RerankerInterface::class);
        $this->vectorRepository->method('findByCollection')->willReturn([]);
        $reranker->expects(self::never())->method('rerank');

        $results = $this->service->findSimilarWithRerank('col', 'query', $reranker);

        self::assertSame([], $results);
    }

    #[Test]
    public function findSimilarWithRerankDelegatesReorderingAndSlicesToTopK(): void
    {
        $reranker = $this->createMock(RerankerInterface::class);

        $this->embeddingClient->method('embed')->willReturn([1.0, 0.0]);
        $this->vectorRepository->method('findByCollection')->willReturn([
            ['identifier' => 'a', 'vector' => [1.0, 0.0]],
            ['identifier' => 'b', 'vector' => [0.9, 0.1]],
            ['identifier' => 'c', 'vector' => [0.8, 0.2]],
        ]);

        $reranker->method('rerank')->willReturn([
            ['identifier' => 'c', 'score' => 1.0],
            ['identifier' => 'b', 'score' => 0.5],
            ['identifier' => 'a', 'score' => 0.25],
        ]);

        $results = $this->service->findSimilarWithRerank('col', 'query', $reranker, topK: 2);

        self::assertCount(2, $results);
        self::assertSame('c', $results[0]['identifier']);
        self::assertSame('b', $results[1]['identifier']);
    }

    // --- embedAndStoreChunked ---

    #[Test]
    public function embedAndStoreChunkedStoresOneEntryPerChunk(): void
    {
        $strategy = $this->createMock(ChunkingStrategyInterface::class);
        $strategy->method('chunk')->willReturn(['First chunk.', 'Second chunk.']);

        $this->vectorRepository->method('findContentHash')->willReturn(null);
        $this->embeddingClient->method('embed')->willReturn([0.1, 0.2]);

        $upsertedIdentifiers = [];
        $this->vectorRepository
            ->expects(self::exactly(2))
            ->method('upsert')
            ->willReturnCallback(static function (string $col, string $id) use (&$upsertedIdentifiers): void {
                $upsertedIdentifiers[] = $id;
            });

        $this->vectorRepository->method('findIdentifiersByPrefix')->willReturn([]);

        $this->service->embedAndStoreChunked('col', '42', 'Full text.', $strategy);

        self::assertSame(['42_chunk_0', '42_chunk_1'], $upsertedIdentifiers);
    }

    #[Test]
    public function embedAndStoreChunkedDeletesStaleChunks(): void
    {
        $strategy = $this->createMock(ChunkingStrategyInterface::class);
        $strategy->method('chunk')->willReturn(['Only chunk.']);

        $this->vectorRepository->method('findContentHash')->willReturn(null);
        $this->embeddingClient->method('embed')->willReturn([0.1]);
        $this->vectorRepository->method('upsert');

        // DB has 3 old chunks; only chunk_0 is still current
        $this->vectorRepository
            ->method('findIdentifiersByPrefix')
            ->willReturn(['42_chunk_0', '42_chunk_1', '42_chunk_2']);

        $deletedIdentifiers = [];
        $this->vectorRepository
            ->expects(self::exactly(2))
            ->method('deleteByIdentifier')
            ->willReturnCallback(static function (string $col, string $id) use (&$deletedIdentifiers): void {
                $deletedIdentifiers[] = $id;
            });

        $this->service->embedAndStoreChunked('col', '42', 'Full text.', $strategy);

        self::assertSame(['42_chunk_1', '42_chunk_2'], $deletedIdentifiers);
    }

    // --- metadata passthrough ---

    #[Test]
    public function embedAndStoreChunkedStoresMetadataOnEveryChunk(): void
    {
        $strategy = $this->createMock(ChunkingStrategyInterface::class);
        $strategy->method('chunk')->willReturn(['First.', 'Second.']);

        $this->vectorRepository->method('findContentHashAndMetadata')->willReturn(null);
        $this->embeddingClient->method('embed')->willReturn([0.1]);
        $this->vectorRepository->method('findIdentifiersByPrefix')->willReturn([]);

        $storedMetadata = [];
        $this->vectorRepository
            ->expects(self::exactly(2))
            ->method('upsert')
            ->willReturnCallback(
                static function (string $c, string $i, array $v, string $h, array $m) use (&$storedMetadata): void {
                    $storedMetadata[] = $m;
                }
            );

        $this->service->embedAndStoreChunked('col', '42', 'Full text.', $strategy, ['sys_language_uid' => 1]);

        self::assertSame([['sys_language_uid' => 1], ['sys_language_uid' => 1]], $storedMetadata);
    }

    #[Test]
    public function findSimilarWithRerankForwardsMetadataFiltersToRetrieval(): void
    {
        $reranker = $this->createMock(RerankerInterface::class);
        $this->embeddingClient->method('embed')->willReturn([1.0, 0.0]);
        $reranker->method('rerank')->willReturnArgument(1);

        $this->vectorRepository
            ->expects(self::once())
            ->method('findByCollection')
            ->with('col', ['sys_language_uid' => 1])
            ->willReturn([['identifier' => 'a', 'vector' => [1.0, 0.0]]]);

        $this->service->findSimilarWithRerank(
            'col',
            'query',
            $reranker,
            metadataFilters: ['sys_language_uid' => 1],
        );
    }

    #[Test]
    public function embedAndStoreUpdatesMetadataWhenOnlyTheMetadataChanged(): void
    {
        $text = 'Unchanged text';

        $this->vectorRepository
            ->method('findContentHashAndMetadata')
            ->willReturn(['hash' => md5($text), 'metadata' => ['sys_language_uid' => 1]]);

        // The text is unchanged, so nothing should be re-embedded or re-upserted...
        $this->embeddingClient->expects(self::never())->method('embed');
        $this->vectorRepository->expects(self::never())->method('upsert');

        // ...but the drifted metadata must still be written.
        $this->vectorRepository
            ->expects(self::once())
            ->method('updateMetadata')
            ->with('col', '1', ['sys_language_uid' => 2]);

        $this->service->embedAndStore('col', 1, $text, ['sys_language_uid' => 2]);
    }

    #[Test]
    public function embedAndStoreWritesNothingWhenTextAndMetadataAreBothUnchanged(): void
    {
        $text = 'Unchanged text';

        $this->vectorRepository
            ->method('findContentHashAndMetadata')
            ->willReturn(['hash' => md5($text), 'metadata' => ['sys_language_uid' => 1]]);

        $this->embeddingClient->expects(self::never())->method('embed');
        $this->vectorRepository->expects(self::never())->method('upsert');
        $this->vectorRepository->expects(self::never())->method('updateMetadata');

        $this->service->embedAndStore('col', 1, $text, ['sys_language_uid' => 1]);
    }

    // --- chunk-aware read and delete ---

    #[Test]
    public function findSimilarLetsOneDocumentFillTopKWhenChunksAreNotCollapsed(): void
    {
        $this->embeddingClient->method('embed')->willReturn([1.0, 0.0]);
        $this->vectorRepository->method('findByCollection')->willReturn([
            ['identifier' => 'doc1_chunk_0', 'vector' => [1.0, 0.0]],
            ['identifier' => 'doc1_chunk_1', 'vector' => [0.99, 0.01]],
            ['identifier' => 'doc1_chunk_2', 'vector' => [0.98, 0.02]],
            ['identifier' => 'doc2_chunk_0', 'vector' => [0.5, 0.5]],
        ]);

        $results = $this->service->findSimilar('col', 'query', 3);

        // Documents the existing behaviour: three near-duplicate passages from one source.
        self::assertSame(['doc1_chunk_0', 'doc1_chunk_1', 'doc1_chunk_2'], array_column($results, 'identifier'));
    }

    #[Test]
    public function collapseChunksKeepsTheBestChunkPerParentAndReturnsParentIdentifiers(): void
    {
        $this->embeddingClient->method('embed')->willReturn([1.0, 0.0]);
        $this->vectorRepository->method('findByCollection')->willReturn([
            ['identifier' => 'doc1_chunk_0', 'vector' => [0.98, 0.02]],
            ['identifier' => 'doc1_chunk_1', 'vector' => [1.0, 0.0]],
            ['identifier' => 'doc1_chunk_2', 'vector' => [0.9, 0.1]],
            ['identifier' => 'doc2_chunk_0', 'vector' => [0.5, 0.5]],
        ]);

        $results = $this->service->findSimilar('col', 'query', 3, collapseChunks: true);

        self::assertSame(['doc1', 'doc2'], array_column($results, 'identifier'));
        // doc1's best chunk is chunk_1, an exact match.
        self::assertEqualsWithDelta(1.0, $results[0]['score'], 0.0001);
    }

    #[Test]
    public function collapseChunksLeavesUnchunkedIdentifiersAlone(): void
    {
        $this->embeddingClient->method('embed')->willReturn([1.0, 0.0]);
        $this->vectorRepository->method('findByCollection')->willReturn([
            ['identifier' => '42', 'vector' => [1.0, 0.0]],
        ]);

        $results = $this->service->findSimilar('col', 'query', 5, collapseChunks: true);

        self::assertSame(['42'], array_column($results, 'identifier'));
    }

    #[Test]
    public function deleteChunkedRemovesOnlyThisDocumentsChunks(): void
    {
        $this->vectorRepository
            ->method('findIdentifiersByPrefix')
            ->with('col', 'faq_chunk_')
            ->willReturn([
                'faq_chunk_0',
                'faq_chunk_1',
                'faq_chunk_overview',    // a standalone document, not a chunk of faq
                'faq_chunk_1_chunk_0',   // a chunk of the document "faq_chunk_1"
                'Faq_chunk_2',           // a different, case-differing document
            ]);

        $deleted = [];
        $this->vectorRepository
            ->method('deleteByIdentifier')
            ->willReturnCallback(static function (string $c, string $i) use (&$deleted): void {
                $deleted[] = $i;
            });

        $count = $this->service->deleteChunked('col', 'faq');

        self::assertSame(['faq_chunk_0', 'faq_chunk_1'], $deleted);
        self::assertSame(2, $count);
    }

    #[Test]
    public function deleteRemovesASingleEntry(): void
    {
        $this->vectorRepository
            ->expects(self::once())
            ->method('deleteByIdentifier')
            ->with('col', '42');

        $this->service->delete('col', 42);
    }

    #[Test]
    public function findSimilarResultsHaveIdentifierAndScoreKeys(): void
    {
        $this->embeddingClient->method('embed')->willReturn([1.0, 0.0]);
        $this->vectorRepository
            ->method('findByCollection')
            ->willReturn([['identifier' => 'abc', 'vector' => [1.0, 0.0]]]);

        $results = $this->service->findSimilar('col', 'query', 5);

        self::assertArrayHasKey('identifier', $results[0]);
        self::assertArrayHasKey('score', $results[0]);
    }
}
