<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Test\Unit\Service;

use JALabs\SymSearch\Api\EmbeddingProviderInterface;
use JALabs\SymSearch\Exception\ProviderException;
use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\Provider\ProviderResolver;
use JALabs\SymSearch\Service\QueryEmbedding;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class QueryEmbeddingTest extends TestCase
{
    private CacheInterface $cache;
    private EmbeddingProviderInterface $provider;
    private QueryEmbedding $service;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getModelVersion')->willReturn('openai:test:4');
        $config->method('getQueryTimeoutMs')->willReturn(800);

        $this->cache = $this->createMock(CacheInterface::class);
        $this->provider = $this->createMock(EmbeddingProviderInterface::class);
        $resolver = $this->createMock(ProviderResolver::class);
        $resolver->method('get')->willReturn($this->provider);

        $this->service = new QueryEmbedding($config, $resolver, $this->cache, new Json(), new NullLogger());
    }

    public function testCacheHitSkipsProvider(): void
    {
        $this->cache->method('load')->willReturn(json_encode([0.1, 0.2]));
        $this->provider->expects($this->never())->method('embed');

        $this->assertSame([0.1, 0.2], $this->service->getVector('space toys', 1));
    }

    public function testCacheMissCallsProviderAndSaves(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->provider->method('embed')->willReturn([[0.3, 0.4]]);
        $this->cache->expects($this->once())->method('save');

        $this->assertSame([0.3, 0.4], $this->service->getVector('space toys', 1));
    }

    public function testProviderFailureReturnsNull(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->provider->method('embed')->willThrowException(new ProviderException('down'));
        $this->cache->expects($this->never())->method('save');

        $this->assertNull($this->service->getVector('space toys', 1));
    }

    public function testEmptyQueryReturnsNull(): void
    {
        $this->assertNull($this->service->getVector('   ', 1));
    }
}
