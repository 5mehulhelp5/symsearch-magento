<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Model\Provider;

use JALabs\SymSearch\Api\EmbeddingProviderInterface;
use JALabs\SymSearch\Model\Config;

class ProviderResolver
{
    /** @param EmbeddingProviderInterface[] $providers keyed by code */
    public function __construct(
        private readonly Config $config,
        private readonly array $providers = []
    ) {
    }

    public function get(): EmbeddingProviderInterface
    {
        $code = $this->config->getProviderCode();
        if (!isset($this->providers[$code])) {
            throw new \InvalidArgumentException("SymSearch: unknown embedding provider '$code'");
        }
        return $this->providers[$code];
    }
}
