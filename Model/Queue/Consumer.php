<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Model\Queue;

use JALabs\SymSearch\Api\EmbeddingProviderInterface;
use JALabs\SymSearch\Exception\ProviderException;
use JALabs\SymSearch\Exception\RateLimitException;
use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\EmbeddingStorage;
use JALabs\SymSearch\Model\Indexer\TextBuilder;
use JALabs\SymSearch\Model\Provider\ProviderResolver;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class Consumer
{
    private const API_TIMEOUT_MS = 60000;
    private const RATE_LIMIT_MAX_RETRIES = 8;
    private const TRANSPORT_MAX_RETRIES = 3;

    public function __construct(
        private readonly Config $config,
        private readonly EmbeddingStorage $storage,
        private readonly TextBuilder $textBuilder,
        private readonly ProviderResolver $providerResolver,
        private readonly CollectionFactory $collectionFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $message): void
    {
        // Disabling mid-flight leaves queued items for the stale sweep to reclaim.
        if (!$this->config->isEnabled()) {
            return;
        }
        try {
            $data = $this->json->unserialize($message);
        } catch (\Throwable $e) {
            $this->logger->warning('[symsearch] malformed queue message skipped');
            return;
        }
        if (!is_array($data) || !isset($data['store_id'], $data['entity_ids']) || !is_array($data['entity_ids'])) {
            $this->logger->warning('[symsearch] malformed queue message skipped');
            return;
        }
        $storeId = (int)$data['store_id'];
        $entityIds = array_map('intval', $data['entity_ids']);
        if (!$entityIds) {
            return;
        }

        try {
            $this->embedProducts($entityIds, $storeId);
        } catch (\Throwable $e) {
            $this->storage->markFailed($entityIds, $storeId);
            $this->logger->error('[symsearch] embedding batch failed: ' . $e->getMessage(), ['store' => $storeId]);
        }
    }

    private function embedProducts(array $entityIds, int $storeId): void
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect($this->config->getEmbedAttributes())
            ->addIdFilter($entityIds);

        $modelVersion = $this->config->getModelVersion();
        $idToHash = [];
        $hashToText = [];
        foreach ($collection as $product) {
            $text = $this->textBuilder->build($product);
            if ($text === '') {
                continue;
            }
            $hash = sha1($text);
            $idToHash[(int)$product->getId()] = $hash;
            $hashToText[$hash] = $text;
        }

        $existing = $this->storage->findExistingVectorHashes(array_keys($hashToText), $modelVersion);
        $missing = array_diff_key($hashToText, $existing);

        if ($missing) {
            $provider = $this->providerResolver->get();
            $throttleMs = $this->config->getThrottleMs();
            foreach (array_chunk($missing, $this->config->getBatchSize(), true) as $chunk) {
                $vectors = $this->embedChunkWithRetry($provider, array_values($chunk));
                if (count($vectors) !== count($chunk)) {
                    throw new ProviderException(
                        'Embedding provider returned ' . count($vectors) . ' vectors for ' . count($chunk) . ' texts'
                    );
                }
                $this->storage->saveVectors(array_combine(array_keys($chunk), $vectors), $modelVersion);
                if ($this->config->isDebug()) {
                    $this->logger->info(sprintf('[symsearch] embedded %d texts (store %d)', count($chunk), $storeId));
                }
                if ($throttleMs > 0) {
                    usleep($throttleMs * 1000);
                }
            }
        }

        $this->storage->markReady($idToHash, $storeId);

        // products that produced no text at all -> mark ready without hash so they don't loop forever
        $noText = array_diff($entityIds, array_keys($idToHash));
        if ($noText) {
            $this->storage->setStatus($noText, $storeId, 'ready');
        }
    }

    /** @return float[][] */
    private function embedChunkWithRetry(EmbeddingProviderInterface $provider, array $texts): array
    {
        $rateLimitAttempt = 0;
        $transportAttempt = 0;
        while (true) {
            try {
                return $provider->embed($texts, self::API_TIMEOUT_MS, EmbeddingProviderInterface::TYPE_DOCUMENT);
            } catch (RateLimitException $e) {
                if (++$rateLimitAttempt > self::RATE_LIMIT_MAX_RETRIES) {
                    throw $e;
                }
                $wait = min(300, $e->getRetryAfterSeconds() + $rateLimitAttempt * 5);
                $this->logger->warning(sprintf(
                    '[symsearch] rate limited, waiting %ds (attempt %d/%d)',
                    $wait,
                    $rateLimitAttempt,
                    self::RATE_LIMIT_MAX_RETRIES
                ));
                sleep($wait);
            } catch (ProviderException $e) {
                if (++$transportAttempt > self::TRANSPORT_MAX_RETRIES) {
                    throw $e;
                }
                $wait = $transportAttempt * 10;
                $this->logger->warning(sprintf(
                    '[symsearch] provider error, retrying in %ds (attempt %d/%d): %s',
                    $wait,
                    $transportAttempt,
                    self::TRANSPORT_MAX_RETRIES,
                    $e->getMessage()
                ));
                sleep($wait);
            }
        }
    }
}
