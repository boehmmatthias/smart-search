<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Service;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Embedding\EmbeddingClientInterface;
use BoehmMatthias\SmartSearch\Repository\VectorRepository;
use BoehmMatthias\SmartSearch\Service\VectorService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class VectorServiceCacheTest extends TestCase
{
    private EmbeddingClientInterface&MockObject $embeddingClient;
    private VectorRepository&MockObject $vectorRepository;
    private SmartSearchConfiguration&MockObject $configuration;
    private FrontendInterface&MockObject $cache;
    private VectorService $service;

    protected function setUp(): void
    {
        $this->embeddingClient = $this->createMock(EmbeddingClientInterface::class);
        $this->vectorRepository = $this->createMock(VectorRepository::class);
        $this->configuration = $this->createMock(SmartSearchConfiguration::class);
        $this->configuration->method('getEmbeddingContextLength')->willReturn(6000);
        $this->cache = $this->createMock(FrontendInterface::class);

        $this->service = new VectorService(
            $this->embeddingClient,
            $this->vectorRepository,
            $this->configuration,
            $this->createMock(LoggerInterface::class),
            $this->cache,
        );
    }

    private function withTtl(int $ttl): void
    {
        $this->configuration->method('getQueryCacheTtl')->willReturn($ttl);
    }

    /**
     * Captures the cache key each set() is called with.
     *
     * @param string[] $keys
     */
    private function captureSetKeys(array &$keys): void
    {
        $this->cache->method('get')->willReturn(false);
        $this->cache->method('set')->willReturnCallback(
            static function (string $key) use (&$keys): void {
                $keys[] = $key;
            },
        );
    }

    private function stubOneVector(): void
    {
        $this->embeddingClient->method('embed')->willReturn([1.0, 0.0]);
        $this->vectorRepository->method('findByCollection')->willReturn([
            ['identifier' => 'a', 'vector' => [1.0, 0.0], 'metadata' => []],
        ]);
    }

    #[Test]
    public function cachedResultsAreReturnedWithoutTouchingTheRepositoryOrEmbeddingServer(): void
    {
        $this->withTtl(3600);
        $cached = [['identifier' => '99', 'score' => 0.9]];
        $this->cache->method('get')->willReturn($cached);

        $this->vectorRepository->expects(self::never())->method('findByCollection');
        $this->embeddingClient->expects(self::never())->method('embed');

        self::assertSame($cached, $this->service->findSimilar('col', 'query'));
    }

    #[Test]
    public function differentMetadataFiltersDoNotShareACacheEntry(): void
    {
        // The defect this whole class exists for. The original key was
        // md5(collection::query::topK), so a search filtered to German returned the English
        // result set — silently, and only for users whose query happened to match.
        $this->withTtl(3600);
        $this->stubOneVector();
        $keys = [];
        $this->captureSetKeys($keys);

        $this->service->findSimilar('col', 'q', 5, ['sys_language_uid' => 0]);
        $this->service->findSimilar('col', 'q', 5, ['sys_language_uid' => 1]);

        self::assertCount(2, $keys);
        self::assertNotSame($keys[0], $keys[1]);
    }

    #[Test]
    public function collapseChunksDoesNotShareACacheEntryEither(): void
    {
        // It changes both the identifiers and the number of results, so it must be in the key.
        $this->withTtl(3600);
        $this->stubOneVector();
        $keys = [];
        $this->captureSetKeys($keys);

        $this->service->findSimilar('col', 'q', 5, [], false);
        $this->service->findSimilar('col', 'q', 5, [], true);

        self::assertNotSame($keys[0], $keys[1]);
    }

    #[Test]
    public function filterOrderDoesNotChangeTheCacheKey(): void
    {
        // Same query, same filters, written in a different order — one entry, not two.
        $this->withTtl(3600);
        $this->stubOneVector();
        $keys = [];
        $this->captureSetKeys($keys);

        $this->service->findSimilar('col', 'q', 5, ['a' => 1, 'b' => 2]);
        $this->service->findSimilar('col', 'q', 5, ['b' => 2, 'a' => 1]);

        self::assertSame($keys[0], $keys[1]);
    }

    #[Test]
    public function aTtlOfZeroDisablesCachingEntirely(): void
    {
        $this->withTtl(0);
        $this->stubOneVector();

        $this->cache->expects(self::never())->method('get');
        $this->cache->expects(self::never())->method('set');

        $this->service->findSimilar('col', 'q');
    }

    #[Test]
    public function storingAVectorInvalidatesTheCollection(): void
    {
        $this->withTtl(3600);
        $this->vectorRepository->method('findContentHashAndMetadata')->willReturn(null);
        $this->embeddingClient->method('embed')->willReturn([0.1]);

        $this->cache
            ->expects(self::once())
            ->method('flushByTag')
            ->with('smartsearch_collection_col');

        $this->service->embedAndStore('col', '1', 'text');
    }

    #[Test]
    public function deletingInvalidatesTheCollection(): void
    {
        // The original only invalidated on write, so a deleted document kept being returned
        // from cache for the full TTL — an hour by default.
        $this->withTtl(3600);

        $this->cache
            ->expects(self::once())
            ->method('flushByTag')
            ->with('smartsearch_collection_col');

        $this->service->delete('col', '1');
    }

    #[Test]
    public function chunkedStorageFlushesOnceForTheWholeDocumentNotOncePerChunk(): void
    {
        $this->withTtl(3600);
        $strategy = $this->createMock(\BoehmMatthias\SmartSearch\Chunking\ChunkingStrategyInterface::class);
        $strategy->method('chunk')->willReturn(['one', 'two', 'three', 'four', 'five']);

        $this->vectorRepository->method('findContentHashAndMetadata')->willReturn(null);
        $this->vectorRepository->method('findIdentifiersByPrefix')->willReturn([]);
        $this->embeddingClient->method('embed')->willReturn([0.1]);

        $this->cache->expects(self::once())->method('flushByTag');

        $this->service->embedAndStoreChunked('col', 'doc', 'text', $strategy);
    }

    #[Test]
    public function cacheTagsAreNormalisedForCollectionNamesWithPunctuation(): void
    {
        // TYPO3 tags allow only [A-Za-z0-9_%\-&], so an unnormalised name would be rejected.
        $this->withTtl(3600);

        $this->cache
            ->expects(self::once())
            ->method('flushByTag')
            ->with('smartsearch_collection_my_ext_articles');

        $this->service->delete('my-ext.articles', '1');
    }
}
