<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use BoehmMatthias\SmartSearch\Chunking\ChunkingStrategyInterface;
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
            ->method('findContentHash')
            ->with('my-collection', '1')
            ->willReturn($hash);

        $this->embeddingClient->expects(self::never())->method('embed');
        $this->vectorRepository->expects(self::never())->method('upsert');

        $this->service->embedAndStore('my-collection', 1, $text);
    }

    #[Test]
    public function embedAndStoreCallsEmbedWhenHashDiffers(): void
    {
        $this->vectorRepository
            ->method('findContentHash')
            ->willReturn('old_hash');

        $this->embeddingClient
            ->expects(self::once())
            ->method('embed')
            ->willReturn([0.1, 0.2]);

        $this->vectorRepository
            ->expects(self::once())
            ->method('upsert');

        $this->service->embedAndStore('my-collection', 1, 'Some text');
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
