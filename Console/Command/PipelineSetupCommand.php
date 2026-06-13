<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Console\Command;

use JALabs\SymSearch\Service\PipelineManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PipelineSetupCommand extends Command
{
    public function __construct(private readonly PipelineManager $pipelineManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('symsearch:pipeline:setup')
            ->setDescription('Create/update the OpenSearch hybrid normalization pipeline and verify engine plugins');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $missing = $this->pipelineManager->missingEnginePlugins();
        if ($missing) {
            $output->writeln('<error>Missing OpenSearch plugins: ' . implode(', ', $missing) . '</error>');
            return Command::FAILURE;
        }
        $output->writeln('<info>Engine plugins OK (knn, neural-search).</info>');

        if (!$this->pipelineManager->apply()) {
            $output->writeln('<error>Failed to create search pipeline.</error>');
            return Command::FAILURE;
        }
        $output->writeln('<info>Search pipeline "' . PipelineManager::PIPELINE_NAME . '" created/updated.</info>');

        return Command::SUCCESS;
    }
}
