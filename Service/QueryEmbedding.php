<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Service;

use JALabs\SymSearch\Api\EmbeddingProviderInterface;
use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\Provider\ProviderResolver;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class QueryEmbedding
{
    private const CACHE_TAG = 'SYMSEARCH_QUERY';
    private const CACHE_TTL = 1209600; // 14 days

    public function __construct(
        private readonly Config $config,
        private readonly ProviderResolver $providerResolver,
        private readonly CacheInterface $cache,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @return float[]|null null = no vector available, caller must fall back to keyword-only */
    public function getVector(string $queryText, int $storeId): ?array
    {
        $normalized = mb_strtolower(trim((string)preg_replace('~\s+~u', ' ', $queryText)));
        if ($normalized === '') {
            return null;
        }

        $cacheId = 'symsearch_q_' . sha1($this->config->getModelVersion() . '|' . $normalized);
        $cached = $this->cache->load($cacheId);
        if ($cached !== false && $cached !== null && $cached !== '') {
            return $this->json->unserialize($cached);
        }

        try {
            $vectors = $this->providerResolver->get()->embed(
                [$normalized],
                $this->config->getQueryTimeoutMs($storeId),
                EmbeddingProviderInterface::TYPE_QUERY
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[symsearch] query embedding failed, falling back to keyword: ' . $e->getMessage());
            return null;
        }

        $vector = $vectors[0] ?? null;
        if (!is_array($vector) || !$vector) {
            return null;
        }

        $this->cache->save($this->json->serialize($vector), $cacheId, [self::CACHE_TAG], self::CACHE_TTL);

        return $vector;
    }
}
