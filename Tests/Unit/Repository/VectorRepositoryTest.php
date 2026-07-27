<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Repository;

use BoehmMatthias\SmartSearch\Repository\VectorRepository;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class VectorRepositoryTest extends TestCase
{
    private QueryBuilder&MockObject $queryBuilder;
    private VectorRepository $repository;

    protected function setUp(): void
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($this->queryBuilder);

        $this->repository = new VectorRepository($connectionPool, $this->createMock(LoggerInterface::class));
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    private function stubQueryReturning(array $rows): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        foreach (['select', 'addSelectLiteral', 'from', 'groupBy', 'orderBy'] as $method) {
            $this->queryBuilder->method($method)->willReturnSelf();
        }
        $this->queryBuilder->method('executeQuery')->willReturn($result);
    }

    #[Test]
    public function getCollectionStatsCastsAggregatesToIntegers(): void
    {
        // Aggregate columns come back as strings on MySQL, so the casts are load-bearing:
        // without them the command layer formats and date()s a string.
        $this->stubQueryReturning([
            ['collection' => 'docs', 'cnt' => '42', 'last_indexed' => '1700000000'],
            ['collection' => 'kb', 'cnt' => '7', 'last_indexed' => '0'],
        ]);

        $stats = $this->repository->getCollectionStats();

        self::assertSame(
            [
                ['collection' => 'docs', 'count' => 42, 'last_indexed' => 1700000000],
                ['collection' => 'kb', 'count' => 7, 'last_indexed' => 0],
            ],
            $stats,
        );
    }

    #[Test]
    public function getCollectionStatsReturnsAnEmptyArrayWhenNothingIsIndexed(): void
    {
        $this->stubQueryReturning([]);

        self::assertSame([], $this->repository->getCollectionStats());
    }

    #[Test]
    public function getCollectionStatsToleratesANullLastIndexed(): void
    {
        // MAX(tstamp) is NULL for a group with no rows, and some drivers surface that.
        $this->stubQueryReturning([
            ['collection' => 'docs', 'cnt' => '1', 'last_indexed' => null],
        ]);

        self::assertSame(0, $this->repository->getCollectionStats()[0]['last_indexed']);
    }

    #[Test]
    public function deleteOrphansRefusesAnEmptyLiveListUnlessExplicitlyAllowed(): void
    {
        // A provider that failed to load returns [] just as readily as one whose source is
        // genuinely empty, and acting on it deletes the entire collection.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1_700_006_001);

        $this->repository->deleteOrphans('docs', []);
    }
}
