<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Console\Command;

use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\EmbeddingStorage;
use JALabs\SymSearch\Model\Queue\Dispatcher;
use JALabs\SymSearch\Model\StaleState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateCommand extends Command
{
    public function __construct(
        private readonly Config $config,
        private readonly EmbeddingStorage $storage,
        private readonly Dispatcher $dispatcher,
        private readonly StaleState $staleState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('symsearch:embed:generate')
            ->setDescription('Seed and queue product embedding generation')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Only this store id')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max items to queue per store', '50000')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Reset ALL items to pending first (full re-embed check)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>Module is disabled (symsearch/general/enabled). Enable it first.</error>');
            return Command::FAILURE;
        }

        $storeOption = $input->getOption('store');

        if ($storeOption !== null && !ctype_digit((string)$storeOption)) {
            $output->writeln('<error>--store must be a positive integer store ID.</error>');
            return Command::FAILURE;
        }

        $stores = $storeOption !== null ? [(int)$storeOption] : $this->storage->getActiveStoreIds();
        $limit = (int)$input->getOption('limit');

        if ($limit < 1) {
            $output->writeln('<error>--limit must be a positive integer (>= 1).</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('force')) {
            $reset = $this->storage->resetAllToPending($storeOption !== null ? (int)$storeOption : null);
            $output->writeln("<comment>Reset $reset items to pending.</comment>");
        }

        foreach ($stores as $storeId) {
            $seeded = $this->storage->seedMissingItems($storeId);
            $dispatched = $this->dispatcher->dispatch($storeId, $limit);
            $output->writeln("<info>Store $storeId: seeded $seeded new items, dispatched $dispatched to queue.</info>");
        }
        $output->writeln('Run the consumer: bin/magento queue:consumers:start jalabsSymsearchEmbed');

        if ($input->getOption('force')) {
            $this->staleState->markSynced();
        } else {
            $this->staleState->markSyncedIfUnset();
        }

        return Command::SUCCESS;
    }
}
