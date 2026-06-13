<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Plugin;

use JALabs\SymSearch\Model\Config;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\CompositeFieldProvider;

class AddKnnFieldToMappingPlugin
{
    public function __construct(private readonly Config $config)
    {
    }

    public function afterGetFields(CompositeFieldProvider $subject, array $fields): array
    {
        if (!$this->config->isEnabled()) {
            return $fields;
        }
        $fields[Config::FIELD_NAME] = [
            'type'      => 'knn_vector',
            'dimension' => $this->config->getDimensions(),
            'method'    => [
                'name'       => 'hnsw',
                'engine'     => 'faiss',
                'space_type' => 'innerproduct',
                'parameters' => [
                    'm'               => 16,
                    'ef_construction' => 100,
                    'encoder'         => ['name' => 'sq', 'parameters' => ['type' => 'fp16']],
                ],
            ],
        ];
        return $fields;
    }
}
