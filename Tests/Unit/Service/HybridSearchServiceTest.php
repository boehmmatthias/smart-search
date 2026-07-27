<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Service;

use BoehmMatthias\SmartSearch\Search\KeywordSearchInterface;
use BoehmMatthias\SmartSearch\Search\NullKeywordSearch;
use BoehmMatthias\SmartSearch\Service\HybridSearchService;
use BoehmMatthias\SmartSearch\Service\VectorService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HybridSearchServiceTest extends TestCase
{
    private VectorService&MockObject $vectorService;
    private KeywordSearchInterface&MockObject $keywordSearch;
    private HybridSearchService $service;

    protected function setUp(): void
    {
        $this->vectorService = $this->createMock(VectorService::class);
        $this->keywordSearch = $this->createMock(KeywordSearchInterface::class);
        $this->service = new HybridSearchService($this->vectorService, $this->keywordSearch);
    }

    /**
     * @param string[] $identifiers
     * @return array<array{identifier: string, score: float}>
     */
    private function semanticHits(array $identifiers): array
    {
        return array_map(
            static fn(string $id, int $i): array => ['identifier' => $id, 'score' => 1.0 - ($i / 100)],
            $identifiers,
            array_keys($identifiers),
        );
    }

    #[Test]
    public function returnsNothingWhenBothSignalsAreEmpty(): void
    {
        $this->vectorService->method('findSimilar')->willReturn([]);
        $this->keywordSearch->method('search')->willReturn([]);

        self::assertSame([], $this->service->findSimilar('col', 'query'));
    }

    #[Test]
    public function aDocumentInBothSignalsOutranksDocumentsInOnlyOne(): void
    {
        // The entire justification for fusing. A is first semantically and B second, but B also
        // appears first by keyword, so B must come out on top.
        $this->vectorService->method('findSimilar')->willReturn($this->semanticHits(['A', 'B']));
        $this->keywordSearch->method('search')->willReturn(['B', 'C']);

        $results = $this->service->findSimilar('col', 'query', 3);

        self::assertSame('B', $results[0]['identifier']);
    }

    #[Test]
    public function retrievesWiderThanTopKSoLowerRankedAgreementCanStillWin(): void
    {
        // Cutting both lists at topK would discard exactly the documents fusion exists to find.
        $this->vectorService
            ->expects(self::once())
            ->method('findSimilar')
            ->with('col', 'query', 20, [], false)
            ->willReturn([]);
        $this->keywordSearch->method('search')->willReturn([]);

        $this->service->findSimilar('col', 'query', 5);
    }

    #[Test]
    public function forwardsMetadataFiltersAndCollapseChunksToTheSemanticSide(): void
    {
        $this->vectorService
            ->expects(self::once())
            ->method('findSimilar')
            ->with('col', 'query', 20, ['sys_language_uid' => 1], true)
            ->willReturn([]);
        $this->keywordSearch->method('search')->willReturn([]);

        $this->service->findSimilar(
            'col',
            'query',
            5,
            metadataFilters: ['sys_language_uid' => 1],
            collapseChunks: true,
        );
    }

    #[Test]
    public function limitsResultsToTopK(): void
    {
        $this->vectorService->method('findSimilar')->willReturn($this->semanticHits(['A', 'B', 'C', 'D']));
        $this->keywordSearch->method('search')->willReturn([]);

        self::assertCount(2, $this->service->findSimilar('col', 'query', 2));
    }

    #[Test]
    public function resultsAreSortedByFusedScoreDescending(): void
    {
        $this->vectorService->method('findSimilar')->willReturn($this->semanticHits(['A', 'B', 'C']));
        $this->keywordSearch->method('search')->willReturn(['C']);

        $scores = array_column($this->service->findSimilar('col', 'query', 3), 'score');

        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores);
    }

    #[Test]
    public function aZeroKeywordWeightYieldsThePureSemanticOrder(): void
    {
        $this->vectorService->method('findSimilar')->willReturn($this->semanticHits(['A', 'B']));
        $this->keywordSearch->method('search')->willReturn(['B', 'A']);

        $results = $this->service->findSimilar('col', 'query', 2, semanticWeight: 1.0, keywordWeight: 0.0);

        self::assertSame(['A', 'B'], array_column($results, 'identifier'));
    }

    #[Test]
    public function rejectsNegativeWeights(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1_700_007_001);

        $this->service->findSimilar('col', 'query', 5, semanticWeight: -1.0);
    }

    #[Test]
    public function rejectsTwoZeroWeightsRatherThanReturningAnArbitraryOrder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1_700_007_002);

        $this->service->findSimilar('col', 'query', 5, semanticWeight: 0.0, keywordWeight: 0.0);
    }

    #[Test]
    public function theDefaultKeywordSearchFindsNothingSoHybridDegradesToSemantic(): void
    {
        // NullKeywordSearch exists so the container can resolve HybridSearchService without a
        // consumer implementation. It must contribute nothing rather than anything wrong.
        $service = new HybridSearchService($this->vectorService, new NullKeywordSearch());
        $this->vectorService->method('findSimilar')->willReturn($this->semanticHits(['A', 'B']));

        $results = $service->findSimilar('col', 'query', 2);

        self::assertSame(['A', 'B'], array_column($results, 'identifier'));
    }
}
