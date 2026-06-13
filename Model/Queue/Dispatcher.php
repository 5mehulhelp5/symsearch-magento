<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Model\Queue;

use JALabs\SymSearch\Model\EmbeddingStorage;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;

class Dispatcher
{
    public const TOPIC = 'jalabs.symsearch.embed';
    private const MESSAGE_SIZE = 500;

    public function __construct(
        private readonly EmbeddingStorage $storage,
        private readonly PublisherInterface $publisher,
        private readonly Json $json
    ) {
    }

    /** Publish pending items for one store as queue messages. Returns count dispatched. */
    public function dispatch(int $storeId, int $limit = 50000): int
    {
        $dispatched = 0;
        while ($dispatched < $limit) {
            $batch = $this->storage->claimPendingBatch($storeId, min(self::MESSAGE_SIZE, $limit - $dispatched));
            if (!$batch) {
                break;
            }
            $this->publisher->publish(self::TOPIC, $this->json->serialize([
                'store_id'   => $storeId,
                'entity_ids' => $batch,
            ]));
            $dispatched += count($batch);
        }
        return $dispatched;
    }
}
