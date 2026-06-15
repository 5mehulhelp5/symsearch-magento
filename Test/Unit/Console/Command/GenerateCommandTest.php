<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Test\Unit\Console\Command;

use JALabs\SymSearch\Console\Command\GenerateCommand;
use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\EmbeddingStorage;
use JALabs\SymSearch\Model\Queue\Dispatcher;
use JALabs\SymSearch\Model\StaleState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateCommandTest extends TestCase
{
    private function tester(StaleState $stale): CommandTester
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);

        $storage = $this->createMock(EmbeddingStorage::class);
        $storage->method('getActiveStoreIds')->willReturn([1]);
        $storage->method('seedMissingItems')->willReturn(0);
        $storage->method('resetAllToPending')->willReturn(0);

        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn(0);

        return new CommandTester(new GenerateCommand($config, $storage, $dispatcher, $stale));
    }

    public function testForceMarksSynced(): void
    {
        $stale = $this->createMock(StaleState::class);
        $stale->expects($this->once())->method('markSynced');
        $stale->expects($this->never())->method('markSyncedIfUnset');
        $this->tester($stale)->execute(['--force' => true]);
    }

    public function testNonForceMarksSyncedIfUnset(): void
    {
        $stale = $this->createMock(StaleState::class);
        $stale->expects($this->once())->method('markSyncedIfUnset');
        $stale->expects($this->never())->method('markSynced');
        $this->tester($stale)->execute([]);
    }
}
