<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class VectorRepository
{
    private const TABLE = 'tx_smartsearch_vector';

    /**
     * Placeholder count per DELETE. Databases cap both bound parameters and packet size, so a
     * sweep spanning thousands of identifiers is split rather than sent as one statement.
     */
    private const DELETE_BATCH_SIZE = 500;

    /**
     * Column types keyed by name rather than by position.
     *
     * DBAL accepts either form, but the positional one is a standing hazard: it is consumed as
     * data values followed by criteria values, so inserting a field anywhere before `vector`
     * silently shifts PARAM_LOB onto a different column and binds the blob as a string. Keying
     * by name makes the same array reusable for both insert() and update() and immune to
     * reordering. Anything not named here defaults to PARAM_STR, which is correct for the rest.
     */
    private const COLUMN_TYPES = [
        'vector' => Connection::PARAM_LOB,
        'tstamp' => Connection::PARAM_INT,
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param float[] $vector
     * @param array<string, scalar> $metadata Arbitrary key-value pairs stored alongside the vector (e.g. ['sys_language_uid' => 1, 'site' => 'main']).
     */
    public function upsert(string $collection, string $identifier, array $vector, string $contentHash, array $metadata = []): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $fields = [
            'vector' => VectorCodec::pack($vector),
            'content_hash' => $contentHash,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'tstamp' => time(),
        ];
        $criteria = ['collection' => $collection, 'identifier' => $identifier];

        if ($this->exists($collection, $identifier)) {
            $connection->update(self::TABLE, $fields, $criteria, self::COLUMN_TYPES);
            return;
        }

        try {
            $connection->insert(self::TABLE, $criteria + $fields, self::COLUMN_TYPES);
        } catch (UniqueConstraintViolationException) {
            // exists() and the insert are separate statements with no transaction between
            // them, and the caller does an HTTP embedding round trip before reaching this
            // point — so the window is wide. Two workers indexing the same record both see
            // "not present" and both insert; the unique key admits one. Falling through to an
            // update is correct in either order and keeps the exception out of the caller's
            // request cycle.
            $connection->update(self::TABLE, $fields, $criteria, self::COLUMN_TYPES);
        }
    }

    public function findContentHash(string $collection, string $identifier): ?string
    {
        $row = $this->findRow($collection, $identifier, ['content_hash']);
        return $row !== null ? (string) $row['content_hash'] : null;
    }

    /**
     * Returns the stored content hash and metadata together, so the caller's change-detection
     * path does not need a second query to decide whether metadata drifted.
     *
     * @return array{hash: string, metadata: array<string, scalar|null>}|null
     */
    public function findContentHashAndMetadata(string $collection, string $identifier): ?array
    {
        $row = $this->findRow($collection, $identifier, ['content_hash', 'metadata']);

        if ($row === null) {
            return null;
        }

        $raw = $row['metadata'] ?? null;

        return [
            'hash' => (string) $row['content_hash'],
            'metadata' => $raw !== '' && $raw !== null
                ? (array) json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR)
                : [],
        ];
    }

    /**
     * Updates only the metadata for an existing entry, leaving the vector and content hash
     * untouched. Used when the source text is unchanged but its metadata is not.
     *
     * @param array<string, scalar> $metadata
     */
    public function updateMetadata(string $collection, string $identifier, array $metadata): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                [
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'tstamp' => time(),
                ],
                ['collection' => $collection, 'identifier' => $identifier],
                self::COLUMN_TYPES,
            );
    }

    /**
     * Returns all vectors for the given collection, optionally filtered by metadata key-value pairs.
     * Metadata filtering is performed in PHP after the DB query (no JSON querying required).
     *
     * @param array<string, scalar> $metadataFilters Only entries whose metadata contains ALL given key-value pairs are returned.
     * @return array<array{identifier: string, vector: float[], metadata: array<string, scalar>}>
     */
    public function findByCollection(string $collection, array $metadataFilters = []): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $result = $queryBuilder
            ->select('identifier', 'vector', 'metadata')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('collection', $queryBuilder->createNamedParameter($collection)),
            )
            ->executeQuery();

        $entries = [];

        // Streamed rather than buffered. fetchAllAssociative() held every raw row in memory
        // while a second pass built the float arrays, so both representations were alive at
        // peak. A float array costs ~16 bytes per element rounded up to the next power of two,
        // so at 1536 dimensions that is ~33 KB retained per row and a 128M limit is reached
        // somewhere around 3,400 rows — and chunking multiplies the row count.
        while (($row = $result->fetchAssociative()) !== false) {
            $identifier = (string) $row['identifier'];

            $meta = $row['metadata'] !== '' && $row['metadata'] !== null
                ? (array) json_decode((string) $row['metadata'], true, 512, JSON_THROW_ON_ERROR)
                : [];

            // Filtering before unpacking is the point: metadata filters cannot reduce the rows
            // the database returns, but they can stop a rejected row ever becoming a float
            // array. Decoding a JSON object is orders of magnitude cheaper than that.
            if ($metadataFilters !== [] && !MetadataFilter::matches($meta, $metadataFilters)) {
                continue;
            }

            // A row that cannot be decoded is dropped rather than allowed to poison the
            // result set with plausible-looking garbage. Logged at error level, because the
            // usual cause is a row predating the packed-binary storage format, which needs a
            // migration and will not fix itself.
            try {
                $vector = VectorCodec::unpack((string) $row['vector']);
            } catch (\RuntimeException $e) {
                $this->logger->error('Undecodable vector — entry skipped', [
                    'collection' => $collection,
                    'identifier' => $identifier,
                    'bytes' => strlen((string) $row['vector']),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $entries[] = [
                'identifier' => $identifier,
                'vector' => $vector,
                'metadata' => $meta,
            ];
        }

        return $entries;
    }

    public function deleteByIdentifier(string $collection, string $identifier): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['collection' => $collection, 'identifier' => $identifier]);
    }

    public function deleteByCollection(string $collection): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['collection' => $collection]);
    }

    /**
     * Every identifier stored for a collection.
     *
     * @return string[]
     */
    public function findAllIdentifiers(string $collection): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('identifier')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('collection', $queryBuilder->createNamedParameter($collection)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): string => (string) $row['identifier'], $rows);
    }

    /**
     * Deletes vectors whose identifiers are NOT in $liveIdentifiers.
     *
     * An empty $liveIdentifiers means "the source has no records left", which would delete the
     * entire collection. That is a legitimate thing to want and a catastrophic thing to do by
     * accident — a provider that fails to load its records returns an empty array just as
     * readily as one whose table is genuinely empty. So it must be stated explicitly rather
     * than silently ignored, which is what the previous guard did.
     *
     * @param string[] $liveIdentifiers Identifiers that still exist in the source data.
     * @param bool $allowEmpty Required to proceed when $liveIdentifiers is empty.
     * @return int Number of vectors deleted.
     * @throws \InvalidArgumentException if $liveIdentifiers is empty and $allowEmpty is false
     */
    public function deleteOrphans(string $collection, array $liveIdentifiers, bool $allowEmpty = false): int
    {
        if ($liveIdentifiers === [] && !$allowEmpty) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Refusing to treat every vector in collection "%s" as an orphan. Pass '
                    . '$allowEmpty if the source really is empty; otherwise the provider failed '
                    . 'to load its identifiers.',
                    $collection,
                ),
                1_700_006_001,
            );
        }

        $orphans = array_values(array_diff($this->findAllIdentifiers($collection), $liveIdentifiers));

        return $this->deleteByIdentifiers($collection, $orphans);
    }

    /**
     * Deletes the given identifiers in as few statements as practical.
     *
     * Batched rather than one DELETE per row: an orphan sweep after a bulk content deletion can
     * span thousands of identifiers, and databases cap both placeholder counts and packet size.
     *
     * @param string[] $identifiers
     * @return int Number of identifiers deleted.
     */
    public function deleteByIdentifiers(string $collection, array $identifiers): int
    {
        if ($identifiers === []) {
            return 0;
        }

        $deleted = 0;

        foreach (array_chunk($identifiers, self::DELETE_BATCH_SIZE) as $batch) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $queryBuilder
                ->delete(self::TABLE)
                ->where(
                    $queryBuilder->expr()->eq('collection', $queryBuilder->createNamedParameter($collection)),
                    $queryBuilder->expr()->in(
                        'identifier',
                        $queryBuilder->createNamedParameter($batch, Connection::PARAM_STR_ARRAY),
                    ),
                )
                ->executeStatement();

            $deleted += count($batch);
        }

        return $deleted;
    }

    /**
     * Aggregate stats per collection: how many vectors it holds and when it was last written.
     *
     * Aggregated in SQL rather than by counting rows in PHP — this is the one place that can
     * afford to ask about every collection at once, precisely because it never loads a vector.
     *
     * @return array<array{collection: string, count: int, last_indexed: int}> Ordered by collection.
     */
    public function getCollectionStats(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('collection')
            ->addSelectLiteral('COUNT(*) AS cnt', 'MAX(tstamp) AS last_indexed')
            ->from(self::TABLE)
            ->groupBy('collection')
            ->orderBy('collection')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): array => [
            'collection' => (string) $row['collection'],
            'count' => (int) $row['cnt'],
            'last_indexed' => (int) $row['last_indexed'],
        ], $rows);
    }

    /**
     * Returns all identifiers in a collection whose identifier starts with $prefix.
     * Used to find and clean up stale chunks after a document is re-chunked.
     *
     * @return string[]
     */
    public function findIdentifiersByPrefix(string $collection, string $prefix): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $rows = $queryBuilder
            ->select('identifier')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('collection', $queryBuilder->createNamedParameter($collection)),
                $queryBuilder->expr()->like('identifier', $queryBuilder->createNamedParameter($this->escapeLikePrefix($prefix))),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_column($rows, 'identifier');
    }

    private function escapeLikePrefix(string $prefix): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
    }

    /** @return array<string, mixed>|null */
    /**
     * Selects only the named columns.
     *
     * This used to be SELECT *, which pulled the whole MEDIUMBLOB across the wire on every
     * change-detection check — roughly 12 KB per record at 3072 dimensions, on the hot path of
     * any re-index run, purely to read a 32-character hash.
     *
     * @param string[] $columns
     * @return array<string, mixed>|null
     */
    private function findRow(string $collection, string $identifier, array $columns): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select(...$columns)
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('collection', $queryBuilder->createNamedParameter($collection)),
                $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    private function exists(string $collection, string $identifier): bool
    {
        return $this->findRow($collection, $identifier, ['uid']) !== null;
    }
}
