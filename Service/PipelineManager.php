<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Service;

use JALabs\SymSearch\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

class PipelineManager
{
    public const PIPELINE_NAME = 'jalabs_symsearch_hybrid';

    public function __construct(
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json
    ) {
    }

    /** Create or update the normalization search pipeline. Returns true on success. */
    public function apply(): bool
    {
        $kw = $this->config->getKeywordWeight();
        $sem = $this->config->getSemanticWeight();
        $sum = $kw + $sem;
        if ($sum <= 0) {
            throw new \InvalidArgumentException('SymSearch: keyword + semantic weights must be > 0');
        }

        $body = [
            'description' => 'JALabs SymSearch hybrid score normalization',
            'phase_results_processors' => [[
                'normalization-processor' => [
                    'normalization' => ['technique' => 'min_max'],
                    'combination'   => [
                        'technique'  => 'arithmetic_mean',
                        'parameters' => ['weights' => [round($kw / $sum, 4), round($sem / $sum, 4)]],
                    ],
                ],
            ]],
        ];

        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
        $curl->post($this->getBaseUrl() . '/_search/pipeline/' . self::PIPELINE_NAME, $this->json->serialize($body));

        return $curl->getStatus() >= 200 && $curl->getStatus() < 300;
    }

    public function exists(): bool
    {
        $curl = $this->curlFactory->create();
        $curl->get($this->getBaseUrl() . '/_search/pipeline/' . self::PIPELINE_NAME);
        return $curl->getStatus() === 200;
    }

    /** @return string[] missing required OpenSearch plugins */
    public function missingEnginePlugins(): array
    {
        $curl = $this->curlFactory->create();
        $curl->get($this->getBaseUrl() . '/_cat/plugins');
        $body = (string)$curl->getBody();
        $missing = [];
        foreach (['opensearch-knn', 'opensearch-neural-search'] as $plugin) {
            if (strpos($body, $plugin) === false) {
                $missing[] = $plugin;
            }
        }
        return $missing;
    }

    private function getBaseUrl(): string
    {
        // Try opensearch_* paths first; fall back to elasticsearch7_* (legacy engine key still used in Magento 2.4.8)
        $host = (string)$this->scopeConfig->getValue('catalog/search/opensearch_server_hostname');
        if ($host === '') {
            $host = (string)$this->scopeConfig->getValue('catalog/search/elasticsearch7_server_hostname');
        }

        $port = (string)$this->scopeConfig->getValue('catalog/search/opensearch_server_port');
        if ($port === '') {
            $port = (string)($this->scopeConfig->getValue('catalog/search/elasticsearch7_server_port') ?: '9200');
        }

        if (strpos($host, '://') === false) {
            $host = 'http://' . $host;
        }
        return rtrim($host, '/') . ':' . $port;
    }
}
