<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Plugin;

use JALabs\SymSearch\Model\Config;
use Magento\Elasticsearch\Model\Adapter\Index\Builder;

class EnableKnnIndexSettingPlugin
{
    public function __construct(private readonly Config $config)
    {
    }

    public function afterBuild(Builder $subject, array $settings): array
    {
        if (!$this->config->isEnabled()) {
            return $settings;
        }
        $settings['index']['knn'] = true;
        $settings['index']['knn.algo_param.ef_search'] = 100;
        // OpenSearch 3.3 Lucene-10.3 NFA wildcard automaton is not safe under concurrent
        // segment search (crashes on Mirasvit case-insensitive wildcards); keep it off.
        $settings['index']['search.concurrent_segment_search.mode'] = 'none';
        return $settings;
    }
}
