<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class VectorRepository
{
    private const TABLE = 'tx_smartsearch_vector';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param float[] $vector
     */
    public function upsert(string $collection, string $identifier, array $vector, string $contentHash): void
    {
        $existing = $this->findRow($collection, $identifier);
        $now = time();
        $packed = $this->packVector($vector);

        if ($existing !== null) {
            $this->connectionPool
                ->getConnectionForTable(self::TABLE)
                ->update(
                    self::TABLE,
                    [
                        'vector' => $packed,
                        'content_hash' => $contentHash,
                        'tstamp' => $now,
                    ],
                    ['collection' => $collection, 'identifier' => $identifier],
                    [Connection::PARAM_LOB, Connection::PARAM_STR, Connection::PARAM_INT]
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
                        'tstamp' => $now,
                    ],
                    [Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_LOB, Connection::PARAM_STR, Connection::PARAM_INT]
                );
        }
    }

    public function findContentHash(string $collection, string $identifier): ?string
    {
        $row = $this->findRow($collection, $identifier);
        return $row !== null ? (string) $row['content_hash'] : null;
    }

    /**
     * Returns all vectors for the given collection.
     *
     * @return array<array{identifier: string, vector: float[]}>
     */
    public function findByCollection(string $collection): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('identifier', 'vector')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('collection', $queryBuilder->createNamedParameter($collection))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(function (array $row): array {
            return [
                'identifier' => (string) $row['identifier'],
                'vector' => $this->unpackVector((string) $row['vector']),
            ];
        }, $rows);
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

    /**
     * Encodes a float array as packed IEEE 754 single-precision (float32) binary.
     * ~4 bytes per dimension vs ~8–14 bytes per dimension in JSON.
     *
     * @param float[] $vector
     */
    private function packVector(array $vector): string
    {
        return pack('f*', ...$vector);
    }

    /**
     * Decodes a packed float32 binary string back to a float array.
     *
     * @return float[]
     */
    private function unpackVector(string $binary): array
    {
        if ($binary === '') {
            return [];
        }
        return array_values((array) unpack('f*', $binary));
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
