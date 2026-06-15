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
use Magento\Framework\Indexer\IndexerRegistry;

class Force extends Embedding implements HttpPostActionInterface
{
    private const FULLTEXT_INDEXER = 'catalogsearch_fulltext';

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        private readonly EmbeddingStorage $storage,
        private readonly StaleState $staleState,
        private readonly IndexerRegistry $indexerRegistry
    ) {
        parent::__construct($context, $resultJsonFactory);
    }

    public function execute(): Json
    {
        try {
            $reset = $this->storage->resetAllToPending(null);
            $this->indexerRegistry->get(self::FULLTEXT_INDEXER)->invalidate();
            $this->staleState->markSynced();
            return $this->json([
                'success' => true,
                'message' => (string)__(
                    'Force re-embed queued: %1 item(s) reset to pending. The catalog search '
                    . 'index was invalidated — reindex it once embedding coverage recovers.',
                    $reset
                ),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
