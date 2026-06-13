<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Cron;

use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\EmbeddingStorage;
use JALabs\SymSearch\Model\Queue\Dispatcher;

class Dispatch
{
    public function __construct(
        private readonly Config $config,
        private readonly EmbeddingStorage $storage,
        private readonly Dispatcher $dispatcher
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        foreach ($this->storage->getActiveStoreIds() as $storeId) {
            $this->dispatcher->dispatch($storeId, 20000);
        }
    }
}
