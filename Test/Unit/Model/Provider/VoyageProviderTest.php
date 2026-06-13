<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Test\Unit\Model\Provider;

use JALabs\SymSearch\Exception\ProviderException;
use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\Provider\VoyageProvider;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class VoyageProviderTest extends TestCase
{
    private Curl $curl;
    private VoyageProvider $provider;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getModel')->willReturn('voyage-3-large');
        $config->method('getDimensions')->willReturn(4);
        $config->method('getApiKey')->willReturn('pa-test');

        $this->curl = $this->createMock(Curl::class);
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $this->provider = new VoyageProvider($config, $curlFactory, new Json());
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
        $this->curl->method('getStatus')->willReturn(401);
        $this->curl->method('getBody')->willReturn('{"error":"unauthorized"}');

        $this->expectException(ProviderException::class);
        $this->provider->embed(['text']);
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
