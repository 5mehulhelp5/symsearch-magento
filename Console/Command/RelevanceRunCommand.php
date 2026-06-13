<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Console\Command;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\State;
use Magento\Search\Api\SearchInterface;
use Magento\Store\Model\App\Emulation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs queries from a CSV (query;expected_skus_pipe_separated;topN) through real
 * storefront search and checks expected SKUs appear in the top N results.
 * --suggest prints the current top 5 SKUs per query instead (for curating the CSV).
 *
 * Uses SearchInterface with an explicit relevance DESC sort order to avoid the
 * malformed _script sort that OpenSearch rejects when no sort orders are set on
 * the fulltext collection path.
 */
class RelevanceRunCommand extends Command
{
    public function __construct(
        private readonly SearchInterface $search,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly Emulation $emulation,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('symsearch:relevance:run')
            ->setDescription('Run the relevance regression suite')
            ->addArgument('file', InputArgument::REQUIRED, 'CSV file path (relative to Magento root)')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store id', '1')
            ->addOption('suggest', null, InputOption::VALUE_NONE, 'Print top-5 SKUs per query instead of asserting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode('frontend');
        } catch (\Magento\Framework\Exception\LocalizedException) {
            // area already set
        }

        $file = $input->getArgument('file');
        if (!is_readable($file)) {
            $output->writeln("<error>Cannot read $file</error>");
            return Command::FAILURE;
        }

        $storeId = (int)$input->getOption('store');
        $suggest = (bool)$input->getOption('suggest');
        $rows = array_map(static fn ($line) => str_getcsv($line, ';'), array_filter(array_map('trim', file($file))));
        $failures = 0;

        foreach ($rows as $row) {
            if (!isset($row[0]) || $row[0] === '' || str_starts_with($row[0], '#')) {
                continue;
            }
            $query = $row[0];
            $expectedRaw = $row[1] ?? '';
            $topN = max(1, (int)($row[2] ?? 10));

            $this->emulation->startEnvironmentEmulation($storeId, 'frontend', true);
            try {
                $filter = $this->filterBuilder->setField('search_term')->setValue($query)->create();
                $filterGroup = $this->filterGroupBuilder->addFilter($filter)->create();
                $sortOrder = $this->sortOrderBuilder->setField('relevance')->setDirection('DESC')->create();

                $criteria = $this->searchCriteriaBuilder->create();
                $criteria->setFilterGroups([$filterGroup]);
                $criteria->setRequestName('quick_search_container');
                $criteria->setPageSize($topN);
                $criteria->setCurrentPage(0);
                $criteria->setSortOrders([$sortOrder]);

                $searchResult = $this->search->search($criteria);

                $productIds = [];
                foreach ($searchResult->getItems() as $item) {
                    $productIds[] = (int)$item->getId();
                }

                $skus = [];
                if ($productIds) {
                    $collection = $this->productCollectionFactory->create();
                    $collection->addIdFilter($productIds);
                    $skuById = [];
                    foreach ($collection as $product) {
                        $skuById[(int)$product->getId()] = (string)$product->getSku();
                    }
                    foreach ($productIds as $id) {   // preserve relevance order
                        if (isset($skuById[$id])) {
                            $skus[] = $skuById[$id];
                        }
                    }
                }
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }

            if ($suggest) {
                $output->writeln(sprintf('%s => %s', $query, implode('|', array_slice($skus, 0, 5))));
                continue;
            }

            $expected = array_filter(explode('|', $expectedRaw));
            $hit = (bool)array_intersect($expected, $skus);
            $output->writeln(sprintf('%s  "%s" (top %d)', $hit ? '<info>PASS</info>' : '<error>FAIL</error>', $query, $topN));
            if (!$hit) {
                $failures++;
                $output->writeln('       got: ' . implode('|', array_slice($skus, 0, 5)));
            }
        }

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
