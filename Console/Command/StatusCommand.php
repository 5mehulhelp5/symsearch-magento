<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Console\Command;

use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Model\EmbeddingStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    public function __construct(
        private readonly EmbeddingStorage $storage,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('symsearch:embed:status')->setDescription('Embedding coverage per store');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Model version: <info>' . $this->config->getModelVersion() . '</info>');
        $table = new Table($output);
        $table->setHeaders(['Store', 'Pending', 'Queued', 'Ready', 'Failed', 'Coverage']);
        foreach ($this->storage->getStatusCounts() as $storeId => $counts) {
            $total = array_sum($counts);
            $ready = $counts['ready'] ?? 0;
            $table->addRow([
                $storeId,
                $counts['pending'] ?? 0,
                $counts['queued'] ?? 0,
                $ready,
                $counts['failed'] ?? 0,
                $total ? sprintf('%.1f%%', 100 * $ready / $total) : '-',
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
