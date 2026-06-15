<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Controller\Adminhtml\Embedding;

use JALabs\SymSearch\Controller\Adminhtml\Embedding;
use JALabs\SymSearch\Model\EmbeddingStorage;
use JALabs\SymSearch\Model\StaleState;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

class Generate extends Embedding implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        private readonly EmbeddingStorage $storage,
        private readonly StaleState $staleState
    ) {
        parent::__construct($context, $resultJsonFactory);
    }

    public function execute(): Json
    {
        try {
            $seeded = 0;
            foreach ($this->storage->getActiveStoreIds() as $storeId) {
                $seeded += $this->storage->seedMissingItems($storeId);
            }
            $this->staleState->markSyncedIfUnset();
            return $this->json([
                'success' => true,
                'message' => (string)__(
                    'Queued embedding generation: %1 new item(s) seeded. The background '
                    . 'consumer will process pending items.',
                    $seeded
                ),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
