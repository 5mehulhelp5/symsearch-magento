<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Test\Unit\Model\AdminNotification;

use JALabs\SymSearch\Model\AdminNotification\StaleEmbeddings;
use JALabs\SymSearch\Model\StaleState;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

class StaleEmbeddingsTest extends TestCase
{
    private function make(bool $stale): StaleEmbeddings
    {
        $state = $this->createMock(StaleState::class);
        $state->method('isStale')->willReturn($stale);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturn('http://admin/config');
        return new StaleEmbeddings($state, $url);
    }

    public function testDisplayedWhenStale(): void
    {
        $this->assertTrue($this->make(true)->isDisplayed());
    }

    public function testHiddenWhenNotStale(): void
    {
        $this->assertFalse($this->make(false)->isDisplayed());
    }

    public function testSeverityIsMajor(): void
    {
        $this->assertSame(MessageInterface::SEVERITY_MAJOR, $this->make(true)->getSeverity());
    }

    public function testTextIsNonEmptyString(): void
    {
        $this->assertNotEmpty((string)$this->make(true)->getText());
    }

    public function testIdentityIsStable(): void
    {
        $this->assertSame($this->make(true)->getIdentity(), $this->make(false)->getIdentity());
    }
}
