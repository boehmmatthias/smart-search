<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Repository;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class VectorRepository
{
    private const TABLE = 'tx_smartsearch_vector';

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
        $existing = $this->findRow($collection, $identifier);
        $now = time();
        $packed = VectorCodec::pack($vector);
        $encodedMetadata = json_encode($metadata, JSON_THROW_ON_ERROR);

        if ($existing !== null) {
            $this->connectionPool
                ->getConnectionForTable(self::TABLE)
                ->update(
                    self::TABLE,
                    [
                        'vector' => $packed,
                        'content_hash' => $contentHash,
                        'metadata' => $encodedMetadata,
                        'tstamp' => $now,
                    ],
                    ['collection' => $collection, 'identifier' => $identifier],
                    [Connection::PARAM_LOB, Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_INT]
                );
        } else {
            $this->connectionPool
                ->getConnectionForTable(self::TABLE)
                ->insert(
                    self::TABLE,
                    [
                        'collection' => $collection,
                        'identifier' => $identifier,
                        'vector' => $packed,
                        'content_hash' => $contentHash,
                        'metadata' => $encodedMetadata,
                        'tstamp' => $now,
                    ],
                    [Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_LOB, Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_INT]
                );
        }
    }

    public function findContentHash(string $collection, string $identifier): ?string
    {
        $row = $this->findRow($collection, $identifier);
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
        $row = $this->findRow($collection, $identifier);

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
                [Connection::PARAM_STR, Connection::PARAM_INT]
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
        $rows = $queryBuilder
            ->select('identifier', 'vector', 'metadata')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('collection', $queryBuilder->createNamedParameter($collection))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $entries = [];
        foreach ($rows as $row) {
            $identifier = (string) $row['identifier'];

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

            $meta = $row['metadata'] !== '' && $row['metadata'] !== null
                ? (array) json_decode((string) $row['metadata'], true, 512, JSON_THROW_ON_ERROR)
                : [];

            $entries[] = [
                'identifier' => $identifier,
                'vector' => $vector,
                'metadata' => $meta,
            ];
        }

        if (empty($metadataFilters)) {
            return $entries;
        }

        return array_values(array_filter(
            $entries,
            static fn(array $entry): bool => MetadataFilter::matches($entry['metadata'], $metadataFilters)
        ));
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
                $queryBuilder->expr()->like('identifier', $queryBuilder->createNamedParameter($this->escapeLikePrefix($prefix)))
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
    private function findRow(string $collection, string $identifier): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('collection', $queryBuilder->createNamedParameter($collection)),
                $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier))
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }
}
