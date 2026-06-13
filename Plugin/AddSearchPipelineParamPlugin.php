<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Plugin;

use JALabs\SymSearch\Service\PipelineManager;
use Magento\OpenSearch\Model\OpenSearch;

/** Adds the normalization search pipeline to requests containing a hybrid query. */
class AddSearchPipelineParamPlugin
{
    public function beforeQuery(OpenSearch $subject, array $query): array
    {
        if (isset($query['body']['query']['hybrid'])) {
            $query['search_pipeline'] = PipelineManager::PIPELINE_NAME;
        }
        return [$query];
    }
}
