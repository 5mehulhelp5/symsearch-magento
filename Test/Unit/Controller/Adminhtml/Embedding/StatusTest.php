<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Test\Unit\Controller\Adminhtml\Embedding;

use JALabs\SymSearch\Controller\Adminhtml\Embedding\Status;
use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\EmbeddingStorage;
use JALabs\SymSearch\Model\StaleState;
use JALabs\SymSearch\Service\PipelineManager;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase
{
    public function testReturnsCoverageJson(): void
    {
        $storage = $this->createMock(EmbeddingStorage::class);
        $storage->method('getStatusCounts')->willReturn([
            13 => ['ready' => 90, 'pending' => 10],
        ]);
        $config = $this->createMock(Config::class);
        $config->method('getModelVersion')->willReturn('gemini:gemini-embedding-001:512');
        $pipeline = $this->createMock(PipelineManager::class);
        $pipeline->method('exists')->willReturn(true);
        $pipeline->method('missingEnginePlugins')->willReturn([]);
        $stale = $this->createMock(StaleState::class);
        $stale->method('isStale')->willReturn(false);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('English');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $captured = [];
        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($d) use (&$captured, $json) {
            $captured = $d; return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $controller = new Status(
            $this->createMock(Context::class),
            $jsonFactory,
            $storage,
            $config,
            $pipeline,
            $stale,
            $storeManager
        );
        $controller->execute();

        $this->assertTrue($captured['success']);
        $this->assertSame('gemini:gemini-embedding-001:512', $captured['model_version']);
        $this->assertTrue($captured['pipeline_ok']);
        $this->assertSame([], $captured['plugins_missing']);
        $this->assertFalse($captured['stale']);
        $this->assertSame(13, $captured['stores'][0]['id']);
        $this->assertSame(90, $captured['stores'][0]['ready']);
        $this->assertSame(100, $captured['stores'][0]['total']);
        $this->assertSame('90.0%', $captured['stores'][0]['coverage']);
    }
}
