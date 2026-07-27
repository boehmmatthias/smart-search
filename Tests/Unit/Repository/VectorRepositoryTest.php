<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Repository;

use BoehmMatthias\SmartSearch\Repository\VectorCodec;
use BoehmMatthias\SmartSearch\Repository\VectorRepository;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
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

    /**
     * findByCollection() streams rows rather than buffering them, so it reads through
     * fetchAssociative() one row at a time until it returns false.
     *
     * @param array<array<string, mixed>> $rows
     */
    private function stubStreamedQueryReturning(array $rows): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturnOnConsecutiveCalls(...[...array_values($rows), false]);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('collection = :dcValue1');

        foreach (['select', 'from', 'where'] as $method) {
            $this->queryBuilder->method($method)->willReturnSelf();
        }
        $this->queryBuilder->method('expr')->willReturn($expressionBuilder);
        $this->queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $this->queryBuilder->method('executeQuery')->willReturn($result);
    }

    #[Test]
    public function undecodableMetadataDoesNotAbortTheWholeSearch(): void
    {
        // json_decode(JSON_THROW_ON_ERROR) throws \JsonException, which extends \Exception and
        // so was not covered by the \RuntimeException handler guarding the vector two lines
        // below it. metadata is a TEXT column, so one truncated multibyte value was enough to
        // turn every findSimilar() call on the collection into an uncaught exception.
        $this->stubStreamedQueryReturning([
            ['identifier' => 'good', 'vector' => VectorCodec::pack([1.0, 0.0]), 'metadata' => '{"lang":1}'],
            ['identifier' => 'broken', 'vector' => VectorCodec::pack([0.0, 1.0]), 'metadata' => '{"lang":'],
        ]);

        $entries = $this->repository->findByCollection('docs');

        self::assertCount(2, $entries);
        self::assertSame(['lang' => 1], $entries[0]['metadata']);
        // The vector is intact, so the row stays searchable — only its filter attributes are
        // unknown, and an empty set matches no non-empty filter, which is the safe direction.
        self::assertSame([], $entries[1]['metadata']);
        self::assertSame([0.0, 1.0], $entries[1]['vector']);
    }

    #[Test]
    public function undecodableMetadataDoesNotMakeARecordImpossibleToReindex(): void
    {
        // Same gap on the write path: embedAndStore() calls this before anything else, so a
        // throw here meant the row could never repair itself.
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['content_hash' => 'abc', 'metadata' => '{"lang":']);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('x = :dcValue1');
        foreach (['select', 'from', 'where'] as $method) {
            $this->queryBuilder->method($method)->willReturnSelf();
        }
        $this->queryBuilder->method('expr')->willReturn($expressionBuilder);
        $this->queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $this->queryBuilder->method('executeQuery')->willReturn($result);

        self::assertSame(
            ['hash' => 'abc', 'metadata' => []],
            $this->repository->findContentHashAndMetadata('docs', '1'),
        );
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
