<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Test\Unit\Model;

use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\StaleState;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use PHPUnit\Framework\TestCase;

class StaleStateTest extends TestCase
{
    private const PATH = 'symsearch/state/embedded_signature';

    private function config(): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('getProviderCode')->willReturn('gemini');
        $config->method('getModel')->willReturn('gemini-embedding-001');
        $config->method('getDimensions')->willReturn(512);
        $config->method('getEmbedAttributes')->willReturn(['name', 'description']);
        return $config;
    }

    public function testSignatureIsStableForSameConfig(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $writer = $this->createMock(WriterInterface::class);
        $a = new StaleState($this->config(), $scope, $writer);
        $b = new StaleState($this->config(), $scope, $writer);
        $this->assertSame($a->currentSignature(), $b->currentSignature());
        $this->assertNotEmpty($a->currentSignature());
    }

    public function testIsStaleTrueWhenStoredDiffers(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->with(self::PATH)->willReturn('different-old-signature');
        $writer = $this->createMock(WriterInterface::class);
        $state = new StaleState($this->config(), $scope, $writer);
        $this->assertTrue($state->isStale());
    }

    public function testIsStaleFalseWhenNoBaselineStored(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->with(self::PATH)->willReturn(null);
        $writer = $this->createMock(WriterInterface::class);
        $state = new StaleState($this->config(), $scope, $writer);
        $this->assertFalse($state->isStale());
    }

    public function testIsStaleFalseWhenStoredMatches(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $writer = $this->createMock(WriterInterface::class);
        $state = new StaleState($this->config(), $scope, $writer);
        $scope2 = $this->createMock(ScopeConfigInterface::class);
        $scope2->method('getValue')->with(self::PATH)->willReturn($state->currentSignature());
        $state2 = new StaleState($this->config(), $scope2, $writer);
        $this->assertFalse($state2->isStale());
    }

    public function testMarkSyncedWritesCurrentSignature(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $writer = $this->createMock(WriterInterface::class);
        $state = new StaleState($this->config(), $scope, $writer);
        $writer->expects($this->once())->method('save')->with(self::PATH, $state->currentSignature());
        $state->markSynced();
    }

    public function testMarkSyncedIfUnsetWritesOnlyWhenEmpty(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->with(self::PATH)->willReturn('');
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())->method('save');
        (new StaleState($this->config(), $scope, $writer))->markSyncedIfUnset();
    }

    public function testMarkSyncedIfUnsetSkipsWhenSet(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->with(self::PATH)->willReturn('existing-sig');
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');
        (new StaleState($this->config(), $scope, $writer))->markSyncedIfUnset();
    }
}
