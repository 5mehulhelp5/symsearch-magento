<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Test\Unit\Model\Provider;

use JALabs\SymSearch\Exception\ProviderException;
use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\Provider\OpenAiProvider;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class OpenAiProviderTest extends TestCase
{
    private Curl $curl;
    private OpenAiProvider $provider;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getModel')->willReturn('text-embedding-3-small');
        $config->method('getDimensions')->willReturn(4);
        $config->method('getApiKey')->willReturn('sk-test');

        $this->curl = $this->createMock(Curl::class);
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $this->provider = new OpenAiProvider($config, $curlFactory, new Json());
    }

    public function testEmbedReturnsVectorsInInputOrder(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([
            'data' => [
                ['index' => 1, 'embedding' => [0.4, 0.5, 0.6, 0.7]],
                ['index' => 0, 'embedding' => [0.1, 0.2, 0.3, 0.4]],
            ],
        ]));

        $result = $this->provider->embed(['first', 'second']);

        $this->assertSame([0.1, 0.2, 0.3, 0.4], $result[0]);
        $this->assertSame([0.4, 0.5, 0.6, 0.7], $result[1]);
    }

    public function testEmbedThrowsOnHttpError(): void
    {
        $this->curl->method('getStatus')->willReturn(429);
        $this->curl->method('getBody')->willReturn('{"error":"rate limited"}');

        $this->expectException(ProviderException::class);
        $this->provider->embed(['text']);
    }

    public function testEmbedThrowsRateLimitExceptionOn429(): void
    {
        $this->curl->method('getStatus')->willReturn(429);
        $this->curl->method('getBody')->willReturn('{"error":"rate limited"}');

        try {
            $this->provider->embed(['text']);
            $this->fail('Expected RateLimitException');
        } catch (\JALabs\SymSearch\Exception\RateLimitException $e) {
            $this->assertSame(30, $e->getRetryAfterSeconds());
        }
    }

    public function testEmbedThrowsOnCountMismatch(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['data' => []]));

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

    public function testEmbedThrowsOnMalformedRow(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(
            json_encode(['data' => [['embedding' => [0.1]]]])
        );

        $this->expectException(ProviderException::class);
        $this->provider->embed(['text']);
    }
}
