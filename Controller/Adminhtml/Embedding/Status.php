<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Controller\Adminhtml\Embedding;

use JALabs\SymSearch\Controller\Adminhtml\Embedding;
use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\EmbeddingStorage;
use JALabs\SymSearch\Model\StaleState;
use JALabs\SymSearch\Service\PipelineManager;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

class Status extends Embedding
{
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        private readonly EmbeddingStorage $storage,
        private readonly Config $config,
        private readonly PipelineManager $pipelineManager,
        private readonly StaleState $staleState,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context, $resultJsonFactory);
    }

    public function execute(): Json
    {
        try {
            $stores = [];
            foreach ($this->storage->getStatusCounts() as $storeId => $counts) {
                $total = array_sum($counts);
                $ready = $counts['ready'] ?? 0;
                $stores[] = [
                    'id'       => (int)$storeId,
                    'label'    => $this->storeLabel((int)$storeId),
                    'pending'  => $counts['pending'] ?? 0,
                    'queued'   => $counts['queued'] ?? 0,
                    'ready'    => $ready,
                    'failed'   => $counts['failed'] ?? 0,
                    'total'    => $total,
                    'coverage' => $total ? sprintf('%.1f%%', 100 * $ready / $total) : '-',
                ];
            }
            $missing = $this->pipelineManager->missingEnginePlugins();
            return $this->json([
                'success'         => true,
                'stores'          => $stores,
                'model_version'   => $this->config->getModelVersion(),
                'pipeline_ok'     => $this->pipelineManager->exists() && empty($missing),
                'plugins_missing' => $missing,
                'stale'           => $this->staleState->isStale(),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function storeLabel(int $storeId): string
    {
        try {
            return (string)$this->storeManager->getStore($storeId)->getName();
        } catch (\Throwable) {
            return 'Store ' . $storeId;
        }
    }
}
