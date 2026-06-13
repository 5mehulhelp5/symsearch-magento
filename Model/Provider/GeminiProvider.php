<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Model\Provider;

use JALabs\SymSearch\Api\EmbeddingProviderInterface;
use JALabs\SymSearch\Exception\ProviderException;
use JALabs\SymSearch\Exception\RateLimitException;
use JALabs\SymSearch\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

class GeminiProvider implements EmbeddingProviderInterface
{
    private const ENDPOINT_TEMPLATE =
        'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContents';

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json
    ) {
    }

    public function getCode(): string
    {
        return 'gemini';
    }

    public function embed(array $texts, int $timeoutMs = 30000, string $inputType = self::TYPE_DOCUMENT): array
    {
        $model    = $this->config->getModel();
        $endpoint = sprintf(self::ENDPOINT_TEMPLATE, $model);

        $taskType = $inputType === self::TYPE_QUERY ? 'RETRIEVAL_QUERY' : 'RETRIEVAL_DOCUMENT';

        $requests = [];
        foreach (array_values($texts) as $text) {
            $requests[] = [
                'model'                => 'models/' . $model,
                'content'              => ['parts' => [['text' => $text]]],
                'taskType'             => $taskType,
                'outputDimensionality' => $this->config->getDimensions(),
            ];
        }

        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_TIMEOUT_MS, $timeoutMs);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT_MS, min($timeoutMs, 3000));
        $curl->addHeader('x-goog-api-key', $this->config->getApiKey());
        $curl->addHeader('Content-Type', 'application/json');

        try {
            $curl->post($endpoint, $this->json->serialize(['requests' => $requests]));
        } catch (\Throwable $e) {
            throw new ProviderException('Gemini embeddings transport error: ' . $e->getMessage(), 0, $e);
        }

        if ($curl->getStatus() === 429) {
            $body = (string)$curl->getBody();
            $retryAfter = 30;
            if (preg_match('~retry in (\d+(?:\.\d+)?)s~i', $body, $m)) {
                $retryAfter = (int)ceil((float)$m[1]);
            }
            throw new RateLimitException(
                sprintf('%s embeddings HTTP 429 (rate limited): %s', 'Gemini', substr($body, 0, 300)),
                $retryAfter
            );
        }

        if ($curl->getStatus() !== 200) {
            throw new ProviderException(sprintf(
                'Gemini embeddings HTTP %d: %s',
                $curl->getStatus(),
                substr((string)$curl->getBody(), 0, 500)
            ));
        }

        try {
            $response = $this->json->unserialize($curl->getBody());
        } catch (\Throwable $e) {
            throw new ProviderException('Gemini embeddings: invalid JSON response', 0, $e);
        }

        if (!isset($response['embeddings']) || count($response['embeddings']) !== count($texts)) {
            throw new ProviderException('Gemini embeddings: response count mismatch');
        }

        $vectors = [];
        foreach ($response['embeddings'] as $i => $row) {
            if (!isset($row['values']) || !is_array($row['values']) || $row['values'] === []) {
                throw new ProviderException('Gemini embeddings: malformed response row at index ' . $i);
            }
            $vectors[] = $this->normalize(array_map('floatval', $row['values']));
        }

        return $vectors;
    }

    private function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(static fn (float $v) => $v * $v, $vector)));
        if ($norm <= 0.0) {
            throw new ProviderException('Gemini embeddings: zero-norm vector');
        }
        return array_map(static fn (float $v) => $v / $norm, $vector);
    }
}
