<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Model\Provider;

use JALabs\SymSearch\Api\EmbeddingProviderInterface;
use JALabs\SymSearch\Exception\ProviderException;
use JALabs\SymSearch\Exception\RateLimitException;
use JALabs\SymSearch\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

class OpenAiProvider implements EmbeddingProviderInterface
{
    private const ENDPOINT = 'https://api.openai.com/v1/embeddings';

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json
    ) {
    }

    public function getCode(): string
    {
        return 'openai';
    }

    public function embed(array $texts, int $timeoutMs = 30000, string $inputType = self::TYPE_DOCUMENT): array
    {
        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_TIMEOUT_MS, $timeoutMs);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT_MS, min($timeoutMs, 3000));
        $curl->addHeader('Authorization', 'Bearer ' . $this->config->getApiKey());
        $curl->addHeader('Content-Type', 'application/json');
        try {
            $curl->post(self::ENDPOINT, $this->json->serialize([
                'model'           => $this->config->getModel(),
                'input'           => array_values($texts),
                'dimensions'      => $this->config->getDimensions(),
                'encoding_format' => 'float',
            ]));
        } catch (\Throwable $e) {
            throw new ProviderException('OpenAI embeddings transport error: ' . $e->getMessage(), 0, $e);
        }

        if ($curl->getStatus() === 429) {
            $body = (string)$curl->getBody();
            $retryAfter = 30;
            if (preg_match('~retry in (\d+(?:\.\d+)?)s~i', $body, $m)) {
                $retryAfter = (int)ceil((float)$m[1]);
            }
            throw new RateLimitException(
                sprintf('%s embeddings HTTP 429 (rate limited): %s', 'OpenAI', substr($body, 0, 300)),
                $retryAfter
            );
        }

        if ($curl->getStatus() !== 200) {
            throw new ProviderException(sprintf(
                'OpenAI embeddings HTTP %d: %s',
                $curl->getStatus(),
                substr((string)$curl->getBody(), 0, 500)
            ));
        }

        try {
            $response = $this->json->unserialize($curl->getBody());
        } catch (\Throwable $e) {
            throw new ProviderException('OpenAI embeddings: invalid JSON response', 0, $e);
        }

        if (!isset($response['data']) || count($response['data']) !== count($texts)) {
            throw new ProviderException('OpenAI embeddings: response count mismatch');
        }

        foreach ($response['data'] as $row) {
            if (!isset($row['index'], $row['embedding']) || !is_array($row['embedding'])) {
                throw new ProviderException('OpenAI embeddings: malformed response row');
            }
        }

        usort($response['data'], static fn (array $a, array $b) => $a['index'] <=> $b['index']);

        return array_map(static fn (array $row) => $row['embedding'], $response['data']);
    }
}
