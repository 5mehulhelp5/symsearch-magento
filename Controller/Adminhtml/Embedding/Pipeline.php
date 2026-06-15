<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Controller\Adminhtml\Embedding;

use JALabs\SymSearch\Controller\Adminhtml\Embedding;
use JALabs\SymSearch\Service\PipelineManager;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

class Pipeline extends Embedding implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        private readonly PipelineManager $pipelineManager
    ) {
        parent::__construct($context, $resultJsonFactory);
    }

    public function execute(): Json
    {
        try {
            $missing = $this->pipelineManager->missingEnginePlugins();
            if ($missing) {
                return $this->json([
                    'success' => false,
                    'message' => (string)__('Missing OpenSearch plugins: %1', implode(', ', $missing)),
                ]);
            }
            if (!$this->pipelineManager->apply()) {
                return $this->json(['success' => false, 'message' => (string)__('Failed to create the search pipeline.')]);
            }
            return $this->json(['success' => true, 'message' => (string)__('Search pipeline created/updated; engine plugins verified.')]);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
