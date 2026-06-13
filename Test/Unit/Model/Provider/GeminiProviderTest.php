<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Test\Unit\Model\Provider;

use JALabs\SymSearch\Exception\ProviderException;
use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\Provider\GeminiProvider;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class GeminiProviderTest extends TestCase
{
    private Curl $curl;
    private GeminiProvider $provider;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getModel')->willReturn('gemini-embedding-001');
        $config->method('getDimensions')->willReturn(4);
        $config->method('getApiKey')->willReturn('test-key');

        $this->curl = $this->createMock(Curl::class);
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $this->provider = new GeminiProvider($config, $curlFactory, new Json());
    }

    public function testEmbedReturnsVectorsInOrder(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([
            'embeddings' => [
                ['values' => [0.6, 0.8, 0.0, 0.0]],
                ['values' => [0.0, 0.0, 0.6, 0.8]],
            ],
        ]));

        $result = $this->provider->embed(['a', 'b']);

        $this->assertCount(2, $result);
        // [0.6, 0.8, 0.0, 0.0] has norm 1.0 already — normalisation leaves it unchanged
        $this->assertEqualsWithDelta(0.6,  $result[0][0], 0.0001);
        $this->assertEqualsWithDelta(0.8,  $result[0][1], 0.0001);
        $this->assertEqualsWithDelta(0.0,  $result[0][2], 0.0001);
        $this->assertEqualsWithDelta(0.0,  $result[0][3], 0.0001);
        // second vector [0.0, 0.0, 0.6, 0.8] — also norm 1.0
        $this->assertEqualsWithDelta(0.0,  $result[1][0], 0.0001);
        $this->assertEqualsWithDelta(0.0,  $result[1][1], 0.0001);
        $this->assertEqualsWithDelta(0.6,  $result[1][2], 0.0001);
        $this->assertEqualsWithDelta(0.8,  $result[1][3], 0.0001);
    }

    public function testEmbedNormalizesVectors(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([
            'embeddings' => [
                ['values' => [3.0, 4.0, 0.0, 0.0]],
            ],
        ]));

        $result = $this->provider->embed(['a']);

        // norm = 5; expected [0.6, 0.8, 0.0, 0.0]
        $this->assertEqualsWithDelta(0.6, $result[0][0], 0.0001);
        $this->assertEqualsWithDelta(0.8, $result[0][1], 0.0001);
        $this->assertEqualsWithDelta(0.0, $result[0][2], 0.0001);
        $this->assertEqualsWithDelta(0.0, $result[0][3], 0.0001);
    }

    public function testEmbedThrowsOnHttpError(): void
    {
        $this->curl->method('getStatus')->willReturn(429);
        $this->curl->method('getBody')->willReturn('{"error":"rate limited"}');

        $this->expectException(ProviderException::class);
        $this->provider->embed(['text']);
    }

    public function testEmbedThrowsRateLimitExceptionWithRetryHintOn429(): void
    {
        $this->curl->method('getStatus')->willReturn(429);
        $this->curl->method('getBody')->willReturn('{"error":{"code":429,"message":"Quota exceeded. Please retry in 58.19s."}}');

        try {
            $this->provider->embed(['text']);
            $this->fail('Expected RateLimitException');
        } catch (\JALabs\SymSearch\Exception\RateLimitException $e) {
            $this->assertSame(59, $e->getRetryAfterSeconds());
        }
    }

    public function testEmbedThrowsOnCountMismatch(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['embeddings' => []]));

        $this->expectException(ProviderException::class);
        $this->provider->embed(['text']);
    }

    public function testEmbedWrapsTransportException(): void
    {
        $this->curl->method('post')->willThrowException(new \Exception('timeout reached'));

        $this->expectException(ProviderException::class);
        $this->provider->embed(['text']);
    }

    public function testEmbedThrowsOnInvalidJson(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('<html>gateway error</html>');

        $this->expectException(ProviderException::class);
        $this->provider->embed(['text']);
    }
}
