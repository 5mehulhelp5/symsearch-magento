<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Model\AdditionalFieldsProvider;

use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\EmbeddingStorage;
use Magento\AdvancedSearch\Model\Adapter\DataMapper\AdditionalFieldsProviderInterface;

class Embedding implements AdditionalFieldsProviderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly EmbeddingStorage $storage
    ) {
    }

    public function getFields(array $productIds, $storeId)
    {
        $result = array_fill_keys(array_map('intval', $productIds), []);
        if (!$this->config->isEnabled()) {
            return $result;
        }
        $vectors = $this->storage->getVectorsForProducts($productIds, (int)$storeId, $this->config->getModelVersion());
        foreach ($vectors as $id => $vector) {
            $result[$id] = [Config::FIELD_NAME => $vector];
        }
        return $result;
    }
}
