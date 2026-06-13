<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

class EmbeddingStorage
{
    private const ITEM_TABLE   = 'jalabs_symsearch_item';
    private const VECTOR_TABLE = 'jalabs_symsearch_vector';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly VectorCodec $codec,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /** @return int[] active non-admin store ids */
    public function getActiveStoreIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($store->getIsActive()) {
                $ids[] = (int)$store->getId();
            }
        }
        return $ids;
    }

    /** Mark products stale across all active stores (product save / import). */
    public function markPendingByProductIds(array $productIds): void
    {
        // Resetting attempts to 0 here is deliberate: when a product is saved its content
        // may have changed, so any previous failure reason no longer applies.  Giving the
        // item a fresh retry budget lets a just-fixed product succeed immediately instead
        // of staying stuck because it exhausted its budget in a prior broken state.
        if (!$productIds) {
            return;
        }
        $connection = $this->resource->getConnection();
        $rows = [];
        foreach ($this->getActiveStoreIds() as $storeId) {
            foreach ($productIds as $id) {
                $rows[] = ['entity_id' => (int)$id, 'store_id' => $storeId, 'status' => 'pending', 'attempts' => 0];
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            $connection->insertOnDuplicate(
                $this->resource->getTableName(self::ITEM_TABLE),
                $chunk,
                ['status', 'attempts']
            );
        }
    }

    /** Create pending item rows for products that have none yet. Returns inserted count. */
    public function seedMissingItems(int $storeId): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], [
                'entity_id' => 'e.entity_id',
                'store_id'  => new \Zend_Db_Expr((string)$storeId),
                'status'    => new \Zend_Db_Expr("'pending'"),
            ])
            ->joinLeft(
                ['i' => $this->resource->getTableName(self::ITEM_TABLE)],
                'i.entity_id = e.entity_id AND i.store_id = ' . $storeId,
                []
            )
            ->where('i.entity_id IS NULL');

        return $connection->query($connection->insertFromSelect(
            $select,
            $this->resource->getTableName(self::ITEM_TABLE),
            ['entity_id', 'store_id', 'status'],
            \Magento\Framework\DB\Adapter\AdapterInterface::INSERT_IGNORE
        ))->rowCount();
    }

    /** @return int[] */
    public function fetchIdsByStatus(int $storeId, string $status, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::ITEM_TABLE), 'entity_id')
            ->where('store_id = ?', $storeId)
            ->where('status = ?', $status)
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }

    /** Atomically claim up to $limit pending items: mark them queued and return their ids. */
    public function claimPendingBatch(int $storeId, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::ITEM_TABLE);
        $connection->beginTransaction();
        try {
            $select = $connection->select()
                ->from($table, 'entity_id')
                ->where('store_id = ?', $storeId)
                ->where('status = ?', 'pending')
                ->limit($limit)
                ->forUpdate(true);
            $ids = array_map('intval', $connection->fetchCol($select));
            if ($ids) {
                $connection->update($table, ['status' => 'queued'], ['entity_id IN (?)' => $ids, 'store_id = ?' => $storeId]);
            }
            $connection->commit();
            return $ids;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    public function setStatus(array $entityIds, int $storeId, string $status): void
    {
        if (!$entityIds) {
            return;
        }
        $connection = $this->resource->getConnection();
        foreach (array_chunk($entityIds, 1000) as $chunk) {
            $connection->update(
                $this->resource->getTableName(self::ITEM_TABLE),
                ['status' => $status],
                ['entity_id IN (?)' => $chunk, 'store_id = ?' => $storeId]
            );
        }
    }

    public function markFailed(array $entityIds, int $storeId): void
    {
        if (!$entityIds) {
            return;
        }
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::ITEM_TABLE),
            ['status' => 'failed', 'attempts' => new \Zend_Db_Expr('attempts + 1')],
            ['entity_id IN (?)' => $entityIds, 'store_id = ?' => $storeId]
        );
    }

    /** @param array<int,string> $idToHash entity_id => content_hash */
    public function markReady(array $idToHash, int $storeId): void
    {
        if (!$idToHash) {
            return;
        }
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            foreach ($idToHash as $entityId => $hash) {
                $connection->update(
                    $this->resource->getTableName(self::ITEM_TABLE),
                    ['status' => 'ready', 'content_hash' => $hash, 'attempts' => 0],
                    ['entity_id = ?' => (int)$entityId, 'store_id = ?' => $storeId]
                );
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /** @return array<string,bool> hash => true for hashes that already have a vector */
    public function findExistingVectorHashes(array $hashes, string $modelVersion): array
    {
        if (!$hashes) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::VECTOR_TABLE), 'content_hash')
            ->where('content_hash IN (?)', array_unique($hashes))
            ->where('model_version = ?', $modelVersion);

        return array_fill_keys($connection->fetchCol($select), true);
    }

    /** @param array<string,float[]> $hashToVector */
    public function saveVectors(array $hashToVector, string $modelVersion): void
    {
        if (!$hashToVector) {
            return;
        }
        $rows = [];
        foreach ($hashToVector as $hash => $vector) {
            $rows[] = [
                'content_hash'  => $hash,
                'model_version' => $modelVersion,
                'vector'        => $this->codec->encode($vector),
            ];
        }
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::VECTOR_TABLE),
            $rows,
            ['vector']
        );
    }

    /** @return array<int,float[]> entity_id => vector, only for status=ready items */
    public function getVectorsForProducts(array $productIds, int $storeId, string $modelVersion): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['i' => $this->resource->getTableName(self::ITEM_TABLE)], ['entity_id'])
            ->join(
                ['v' => $this->resource->getTableName(self::VECTOR_TABLE)],
                'v.content_hash = i.content_hash AND v.model_version = ' . $connection->quote($modelVersion),
                ['vector']
            )
            ->where('i.entity_id IN (?)', $productIds)
            ->where('i.store_id = ?', $storeId)
            ->where('i.status = ?', 'ready');

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int)$row['entity_id']] = $this->codec->decode($row['vector']);
        }
        return $result;
    }

    /** @return array<int,array<string,int>> store_id => [status => count] */
    public function getStatusCounts(): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::ITEM_TABLE), ['store_id', 'status', 'cnt' => 'COUNT(*)'])
            ->group(['store_id', 'status']);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int)$row['store_id']][$row['status']] = (int)$row['cnt'];
        }
        return $result;
    }

    public function resetAllToPending(?int $storeId = null): int
    {
        $where = $storeId !== null ? ['store_id = ?' => $storeId] : [];
        return $this->resource->getConnection()->update(
            $this->resource->getTableName(self::ITEM_TABLE),
            ['status' => 'pending', 'attempts' => 0],
            $where
        );
    }

    /** queued items older than $seconds go back to pending (lost messages). */
    public function resetStaleQueued(int $seconds): int
    {
        $connection = $this->resource->getConnection();
        return $connection->update(
            $this->resource->getTableName(self::ITEM_TABLE),
            ['status' => 'pending', 'attempts' => 0],
            [
                'status = ?' => 'queued',
                'updated_at < ?' => (new \DateTimeImmutable("-$seconds seconds"))->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function retryFailed(int $maxAttempts): int
    {
        return $this->resource->getConnection()->update(
            $this->resource->getTableName(self::ITEM_TABLE),
            ['status' => 'pending'],
            ['status = ?' => 'failed', 'attempts < ?' => $maxAttempts]
        );
    }

    /** Safety net for imports/API writes that bypass save events. */
    public function markChangedProductsPending(): int
    {
        $connection = $this->resource->getConnection();
        $item = $this->resource->getTableName(self::ITEM_TABLE);
        $entity = $this->resource->getTableName('catalog_product_entity');

        return $connection->query(
            "UPDATE $item i JOIN $entity e ON e.entity_id = i.entity_id
             SET i.status = 'pending'
             WHERE i.status = 'ready' AND e.updated_at > i.updated_at"
        )->rowCount();
    }
}
