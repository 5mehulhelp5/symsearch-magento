<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Observer;

use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\EmbeddingStorage;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class MarkProductStaleObserver implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly EmbeddingStorage $storage
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        $product = $observer->getEvent()->getProduct();
        if ($product && $product->getId()) {
            $this->storage->markPendingByProductIds([(int)$product->getId()]);
        }
    }
}
