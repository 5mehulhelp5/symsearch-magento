<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Test\Unit\Controller\Adminhtml\Embedding;

use JALabs\SymSearch\Controller\Adminhtml\Embedding\Force;
use JALabs\SymSearch\Model\EmbeddingStorage;
use JALabs\SymSearch\Model\StaleState;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\TestCase;

class ForceTest extends TestCase
{
    public function testResetsInvalidatesAndMarksSynced(): void
    {
        $storage = $this->createMock(EmbeddingStorage::class);
        $storage->expects($this->once())->method('resetAllToPending')->with(null)->willReturn(296306);

        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects($this->once())->method('invalidate');
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('get')->with('catalogsearch_fulltext')->willReturn($indexer);

        $stale = $this->createMock(StaleState::class);
        $stale->expects($this->once())->method('markSynced');

        $captured = [];
        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($d) use (&$captured, $json) {
            $captured = $d; return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $controller = new Force(
            $this->createMock(Context::class),
            $jsonFactory,
            $storage,
            $stale,
            $registry
        );
        $controller->execute();

        $this->assertTrue($captured['success']);
        $this->assertStringContainsString('296306', $captured['message']);
    }
}
