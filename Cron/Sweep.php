<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Cron;

use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\EmbeddingStorage;
use Psr\Log\LoggerInterface;

class Sweep
{
    private const MAX_ATTEMPTS = 5;
    private const QUEUED_TTL_SECONDS = 3600;

    public function __construct(
        private readonly Config $config,
        private readonly EmbeddingStorage $storage,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        $stale   = 0;
        $retried = 0;
        $changed = 0;
        $seeded  = 0;

        try {
            $stale = $this->storage->resetStaleQueued(self::QUEUED_TTL_SECONDS);
        } catch (\Throwable $e) {
            $this->logger->warning('[symsearch] sweep step resetStaleQueued failed: ' . $e->getMessage());
        }

        try {
            $retried = $this->storage->retryFailed(self::MAX_ATTEMPTS);
        } catch (\Throwable $e) {
            $this->logger->warning('[symsearch] sweep step retryFailed failed: ' . $e->getMessage());
        }

        try {
            $changed = $this->storage->markChangedProductsPending();
        } catch (\Throwable $e) {
            $this->logger->warning('[symsearch] sweep step markChangedProductsPending failed: ' . $e->getMessage());
        }

        try {
            foreach ($this->storage->getActiveStoreIds() as $storeId) {
                $seeded += $this->storage->seedMissingItems($storeId);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[symsearch] sweep step seedMissingItems failed: ' . $e->getMessage());
        }

        $this->logger->info(sprintf(
            '[symsearch] sweep: %d stale-queued reset, %d failed retried, %d changed re-marked, %d new seeded',
            $stale, $retried, $changed, $seeded
        ));
    }
}
