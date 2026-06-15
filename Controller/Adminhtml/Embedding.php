<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

abstract class Embedding extends Action
{
    public const ADMIN_RESOURCE = 'JALabs_SymSearch::operations';

    public function __construct(
        Context $context,
        protected readonly JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
    }

    protected function json(array $data): Json
    {
        return $this->resultJsonFactory->create()->setData($data);
    }
}
