<?php

namespace App\Command;

use App\Entity\Boutique;
use App\Repository\BoutiqueRepository;
use App\Service\PrestaShopCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:collect-prestashop-data',
    description: 'Collect stock data from PrestaShop boutiques',
)]
class CollectPrestashopDataCommand extends Command
{
    private BoutiqueRepository $boutiqueRepository;
    private PrestaShopCollector $collector;

    public function __construct(
        BoutiqueRepository $boutiqueRepository,
        PrestaShopCollector $collector
    ) {
        parent::__construct();
        $this->boutiqueRepository = $boutiqueRepository;
        $this->collector = $collector;
    }

    protected function configure(): void
    {
        $this
            ->addOption('boutique', 'b', InputOption::VALUE_OPTIONAL, 'Boutique ID to collect data for')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Collect data for all boutiques')
            ->addOption('branding', null, InputOption::VALUE_NONE, 'Also collect branding data (logo, colors, etc.)')
            ->addOption('orders', 'o', InputOption::VALUE_NONE, 'Also collect orders data')
            ->addOption('orders-days', null, InputOption::VALUE_OPTIONAL, 'Number of days to collect orders for', 30);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $boutiqueId = $input->getOption('boutique');
        $collectAll = $input->getOption('all');
        $collectBranding = $input->getOption('branding');
        $collectOrders = $input->getOption('orders');
        $ordersDays = (int) $input->getOption('orders-days');

        // Determine which boutiques to process
        $boutiques = [];
        if ($boutiqueId) {
            $boutique = $this->boutiqueRepository->find($boutiqueId);
            if (!$boutique) {
                $io->error(sprintf('Boutique with ID %s not found.', $boutiqueId));
                return Command::FAILURE;
            }
            $boutiques[] = $boutique;
        } elseif ($collectAll) {
            $boutiques = $this->boutiqueRepository->findAll();
        } else {
            $io->error('Please specify either --boutique=ID or --all option.');
            return Command::FAILURE;
        }

        if (empty($boutiques)) {
            $io->warning('No boutiques found to process.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Collecting data for %d boutique(s)', count($boutiques)));

        $successCount = 0;
        $errorCount = 0;

        foreach ($boutiques as $boutique) {
            $io->section(sprintf('Processing: %s (ID: %d)', $boutique->getName(), $boutique->getId()));

            // Collect stock data
            $result = $this->collector->collectStockData($boutique);

            if ($result['success']) {
                $io->success(sprintf(
                    'Stock data collected successfully: %d products, %d stocks saved',
                    $result['products_count'],
                    $result['saved_count']
                ));
                $successCount++;

                // Optionally collect branding data
                if ($collectBranding) {
                    $brandingResult = $this->collector->collectBrandingData($boutique);
                    if ($brandingResult['success']) {
                        $io->info('Branding data collected successfully');
                    } else {
                        $io->warning('Could not collect branding data: ' . $brandingResult['error']);
                    }
                }

                // Optionally collect orders data
                if ($collectOrders) {
                    $ordersResult = $this->collector->collectOrdersData($boutique, $ordersDays);
                    if ($ordersResult['success']) {
                        $io->info(sprintf(
                            'Orders data collected successfully: %d orders saved',
                            $ordersResult['saved_count']
                        ));
                    } else {
                        $io->warning('Could not collect orders data: ' . $ordersResult['error']);
                    }
                }
            } else {
                $io->error('Failed to collect data: ' . $result['error']);
                $errorCount++;
            }
        }

        // Summary
        $io->section('Summary');
        $io->table(
            ['Status', 'Count'],
            [
                ['Success', $successCount],
                ['Errors', $errorCount],
                ['Total', count($boutiques)]
            ]
        );

        return $errorCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
