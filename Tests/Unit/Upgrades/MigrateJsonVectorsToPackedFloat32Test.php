<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Upgrades;

use BoehmMatthias\SmartSearch\Repository\VectorCodec;
use BoehmMatthias\SmartSearch\Upgrades\MigrateJsonVectorsToPackedFloat32;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BlobType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * The wizard is driven against an in-memory stand-in for the table: rows that still hold JSON
 * are returned by the batch query, and a converted row is removed from that set exactly as the
 * real `LIKE '[%'` constraint stops matching it once rewritten.
 */
final class MigrateJsonVectorsToPackedFloat32Test extends TestCase
{
    /**
     * Rows still holding JSON, keyed by uid — the fixture's stand-in for the table.
     *
     * @var array<int, string>
     */
    private array $legacyRows = [];

    /** @var array<int, string> uid => packed value written by the wizard */
    private array $written = [];

    private int $fetchCount = 0;

    /**
     * A wizard that cannot terminate would hang the test run, so the batch query refuses to
     * answer indefinitely. The bound is far above what any correct run needs for this fixture.
     */
    private const MAX_FETCHES = 12;

    private function makeWizard(): MigrateJsonVectorsToPackedFloat32
    {
        $connection = $this->createMock(Connection::class);

        // ensureVectorColumnIsBinary() returns early once the column is already a blob, which
        // keeps the platform-specific ALTER out of this test.
        $column = $this->createMock(Column::class);
        $column->method('getType')->willReturn(new BlobType());
        $table = $this->createMock(Table::class);
        $table->method('getColumn')->with('vector')->willReturn($column);
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('introspectTable')->willReturn($table);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $connection->method('update')->willReturnCallback(
            function (string $table, array $data, array $criteria): int {
                $uid = (int) $criteria['uid'];
                $this->written[$uid] = (string) $data['vector'];
                unset($this->legacyRows[$uid]);

                return 1;
            },
        );

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturnCallback(function (): array {
            if (++$this->fetchCount > self::MAX_FETCHES) {
                self::fail(sprintf(
                    'executeUpdate() did not terminate: the batch query was issued more than %d '
                    . 'times over a %d-row fixture.',
                    self::MAX_FETCHES,
                    count($this->written) + count($this->legacyRows),
                ));
            }

            $rows = [];
            foreach ($this->legacyRows as $uid => $vector) {
                $rows[] = ['uid' => $uid, 'vector' => $vector];
            }

            return $rows;
        });

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('like')->willReturn('vector LIKE :dcValue1');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'orderBy', 'setMaxResults'] as $method) {
            $queryBuilder->method($method)->willReturnSelf();
        }
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return new MigrateJsonVectorsToPackedFloat32($connectionPool);
    }

    #[Test]
    public function terminatesWhenSomeRowsCannotBeConverted(): void
    {
        // A row the wizard cannot convert keeps matching the batch query forever. The original
        // guard compared the running failure total against the current batch size, so it only
        // ever fired when every failure happened inside the first batch — with one good row and
        // one bad one, the tail batch is 1 row against a failure total of 2, 3, 4 … and the
        // wizard re-fetched and re-failed the same row indefinitely.
        $this->legacyRows = [
            1 => '[0.1,0.2,0.3]',
            2 => 'not json at all',
        ];

        self::assertTrue($this->makeWizard()->executeUpdate());

        // The convertible row was converted, and the unconvertible one was left untouched.
        self::assertSame([1], array_keys($this->written));
        self::assertSame(VectorCodec::packLegacyJson('[0.1,0.2,0.3]'), $this->written[1]);
        self::assertSame([2], array_keys($this->legacyRows));
    }

    #[Test]
    public function convertsEveryRowWhenAllOfThemAreValid(): void
    {
        $this->legacyRows = [
            1 => '[0.1,0.2]',
            2 => '[0.3,0.4]',
            3 => '[0.5,0.6]',
        ];

        self::assertTrue($this->makeWizard()->executeUpdate());

        self::assertSame([1, 2, 3], array_keys($this->written));
        self::assertSame([], $this->legacyRows);
    }

    #[Test]
    public function terminatesWhenNoRowCanBeConverted(): void
    {
        $this->legacyRows = [
            1 => 'not json at all',
            2 => '[]',
        ];

        self::assertTrue($this->makeWizard()->executeUpdate());

        self::assertSame([], $this->written);
    }
}
