<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Upgrades;

use BoehmMatthias\SmartSearch\Repository\VectorCodec;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\BlobType;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\ChattyInterface;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;

/**
 * Converts vectors stored as JSON text into packed IEEE 754 float32 binary.
 *
 * Before 0.2.0 the `vector` column was a LONGTEXT holding a JSON float array. It is now a
 * MEDIUMBLOB holding pack('f*') output. The column type change alone does not convert the
 * data — on MySQL the JSON bytes survive the ALTER untouched — and nothing downstream can
 * detect the difference, because reading that text with unpack('f*') yields finite,
 * plausible floats of the wrong dimension rather than an error.
 *
 * Recovery is not possible without this wizard: `content_hash` still matches the source
 * text, so VectorService::embedAndStore() short-circuits and a full re-index repairs
 * nothing.
 *
 * The conversion is a pure re-encode. It needs no embedding server, and it deliberately
 * leaves `content_hash` alone — only the encoding changed, not the text it was derived from.
 */
#[UpgradeWizard('smartSearchMigrateJsonVectorsToPackedFloat32')]
final class MigrateJsonVectorsToPackedFloat32 implements UpgradeWizardInterface, ChattyInterface
{
    private const TABLE = 'tx_smartsearch_vector';

    /**
     * Rows are converted in batches so a large collection does not have to be held in memory
     * all at once. Each row carries one vector, so this is a few MB at realistic dimensions.
     */
    private const BATCH_SIZE = 500;

    private OutputInterface $output;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function getTitle(): string
    {
        return 'SmartSearch: convert JSON vectors to packed float32 binary';
    }

    public function getDescription(): string
    {
        return 'Vectors stored before version 0.2.0 are JSON text in a column that is now read as '
            . 'packed float32 binary. Left unconverted they decode to meaningless floats and every '
            . 'search returns no results, and re-indexing cannot repair them because the content '
            . 'hash still matches. This converts them in place. No embedding server is required.';
    }

    /**
     * Deliberately does NOT declare DatabaseUpdatedPrerequisite.
     *
     * That prerequisite runs the schema migrator with $createOnly = true, and
     * ConnectionMigrator::install() discards `changedColumns` in that mode — so it guarantees
     * added columns exist but says nothing about `vector` having become a blob. Relying on it
     * would let this wizard run against a still-LONGTEXT column and write binary into a
     * utf8mb4 field, turning recoverable rows into unrecoverable ones. The column type is
     * therefore checked and corrected by executeUpdate() itself.
     *
     * @return string[]
     */
    public function getPrerequisites(): array
    {
        return [];
    }

    public function updateNecessary(): bool
    {
        return $this->countLegacyRows() > 0;
    }

    public function executeUpdate(): bool
    {
        $this->ensureVectorColumnIsBinary();

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $converted = 0;
        $failed = 0;

        while (true) {
            $rows = $this->fetchLegacyBatch();
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $uid = (int) $row['uid'];
                $packed = $this->repack((string) $row['vector'], $uid);

                if ($packed === null) {
                    $failed++;
                    continue;
                }

                $connection->update(
                    self::TABLE,
                    ['vector' => $packed],
                    ['uid' => $uid],
                    [Connection::PARAM_LOB],
                );
                $converted++;
            }

            // A row that could not be converted still matches the batch query, so a fixed
            // batch size would loop forever once one is hit.
            if ($failed > 0 && count($rows) === $failed) {
                break;
            }
        }

        $this->write(sprintf('Converted %d vector(s) to packed float32.', $converted));

        if ($failed > 0) {
            $this->write(sprintf(
                '%d row(s) could not be converted and were left untouched. They will be skipped '
                . 'by search and logged at error level; re-embed those records to restore them.',
                $failed,
            ));
        }

        return true;
    }

    /**
     * Re-encodes one JSON vector as packed float32, or returns null if the value cannot be
     * converted. A bad row is reported and left alone rather than aborting the whole run.
     */
    private function repack(string $value, int $uid): ?string
    {
        try {
            return VectorCodec::packLegacyJson($value);
        } catch (\RuntimeException $e) {
            $this->write(sprintf('  uid %d: %s Skipped.', $uid, $e->getMessage()));
            return null;
        }
    }

    /**
     * Brings the `vector` column to a binary type before any row is rewritten.
     *
     * Doctrine cannot express the PostgreSQL conversion at all — its ALTER path emits no
     * USING clause, so `ALTER COLUMN vector TYPE BYTEA` fails with "cannot be cast
     * automatically to type bytea". That is why this is issued here rather than left to the
     * schema migrator.
     */
    private function ensureVectorColumnIsBinary(): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $column = $connection->createSchemaManager()
            ->introspectTable(self::TABLE)
            ->getColumn('vector');

        if ($column->getType() instanceof BlobType) {
            return;
        }

        $platform = $connection->getDatabasePlatform();

        $sql = match (true) {
            $platform instanceof AbstractMySQLPlatform
                => 'ALTER TABLE ' . self::TABLE . ' MODIFY vector MEDIUMBLOB NOT NULL',
            $platform instanceof PostgreSQLPlatform
                => 'ALTER TABLE ' . self::TABLE . ' ALTER COLUMN vector TYPE BYTEA USING vector::bytea',
            // SQLite uses type affinity, not strict typing: a column declared TEXT stores a
            // BLOB value as a BLOB without converting it, so no ALTER is needed.
            $platform instanceof SQLitePlatform => null,
            default => throw new \RuntimeException(
                sprintf(
                    'Cannot convert the vector column on database platform "%s". Change it to a '
                    . 'binary type (MEDIUMBLOB or equivalent) manually, then run this wizard again.',
                    $platform::class,
                ),
                1_700_005_001,
            ),
        };

        if ($sql === null) {
            return;
        }

        $this->write('Converting the vector column to a binary type.');
        $connection->executeStatement($sql);
    }

    /**
     * @return array<array{uid: int|string, vector: string}>
     */
    private function fetchLegacyBatch(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        /** @var array<array{uid: int|string, vector: string}> $rows */
        $rows = $queryBuilder
            ->select('uid', 'vector')
            ->from(self::TABLE)
            ->where($this->legacyRowConstraint($queryBuilder))
            ->orderBy('uid')
            ->setMaxResults(self::BATCH_SIZE)
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    private function countLegacyRows(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return (int) $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($this->legacyRowConstraint($queryBuilder))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * A JSON float array always starts with '[', which packed float32 output effectively never
     * does — that would require a first component whose low byte is 0x5B, and the three bytes
     * after it to spell a plausible prefix. Matching on it is what makes this wizard
     * idempotent and safe to re-run.
     */
    private function legacyRowConstraint(\TYPO3\CMS\Core\Database\Query\QueryBuilder $queryBuilder): string
    {
        return $queryBuilder->expr()->like(
            'vector',
            $queryBuilder->createNamedParameter('[%'),
        );
    }

    private function write(string $message): void
    {
        if (isset($this->output)) {
            $this->output->writeln($message);
        }
    }
}
