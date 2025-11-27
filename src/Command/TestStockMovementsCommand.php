<?php

namespace App\Command;

use App\Repository\BoutiqueRepository;
use App\Service\PrestaShopCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-stock-movements',
    description: 'Test fetching stock movements from PrestaShop API',
)]
class TestStockMovementsCommand extends Command
{
    public function __construct(
        private BoutiqueRepository $boutiqueRepository,
        private PrestaShopCollector $prestaShopCollector
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('boutiqueId', InputArgument::REQUIRED, 'The boutique ID to test')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $boutiqueId = $input->getArgument('boutiqueId');

        $boutique = $this->boutiqueRepository->find($boutiqueId);

        if (!$boutique) {
            $io->error('Boutique not found with ID: ' . $boutiqueId);
            return Command::FAILURE;
        }

        $io->title('Testing Stock Movements API for: ' . $boutique->getName());
        $io->info('Domain: ' . $boutique->getDomain());

        // Test fetching stock movements
        $io->section('Fetching stock movements...');

        $movements = $this->prestaShopCollector->fetchStockMovements($boutique);

        if (empty($movements)) {
            $io->warning('No stock movements found or API not accessible');
            return Command::SUCCESS;
        }

        $io->success('Found ' . count($movements) . ' stock movements!');

        // Display first 5 movements as example
        $io->section('Sample movements (first 5):');

        $sampleMovements = array_slice($movements, 0, 5);
        $rows = [];

        foreach ($sampleMovements as $movement) {
            $rows[] = [
                $movement['id'] ?? 'N/A',
                $movement['id_product'] ?? 'N/A',
                $movement['product_name'] ?? 'N/A',
                $movement['reference'] ?? 'N/A',
                $movement['physical_quantity'] ?? 'N/A',
                $movement['sign'] ?? 'N/A',
                $movement['date_add'] ?? 'N/A',
                $movement['id_stock_mvt_reason'] ?? 'N/A',
            ];
        }

        $io->table(
            ['ID', 'Product ID', 'Product Name', 'Reference', 'Quantity', 'Sign', 'Date', 'Reason ID'],
            $rows
        );

        $io->success('Stock movements API is accessible!');
        $io->note('You can now use this data to track real restocking events.');

        return Command::SUCCESS;
    }
}
