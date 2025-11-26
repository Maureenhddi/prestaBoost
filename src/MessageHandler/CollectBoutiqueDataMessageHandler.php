<?php

namespace App\MessageHandler;

use App\Message\CollectBoutiqueDataMessage;
use App\Repository\BoutiqueRepository;
use App\Service\PrestaShopCollector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class CollectBoutiqueDataMessageHandler
{
    public function __construct(
        private BoutiqueRepository $boutiqueRepository,
        private PrestaShopCollector $collector,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(CollectBoutiqueDataMessage $message): void
    {
        $boutiqueId = $message->getBoutiqueId();

        $this->logger->info('Processing data collection job', [
            'boutique_id' => $boutiqueId,
            'collect_stocks' => $message->shouldCollectStocks(),
            'collect_orders' => $message->shouldCollectOrders(),
            'orders_days' => $message->getOrdersDays()
        ]);

        $boutique = $this->boutiqueRepository->find($boutiqueId);

        if (!$boutique) {
            $this->logger->error('Boutique not found for data collection', [
                'boutique_id' => $boutiqueId
            ]);
            return;
        }

        try {
            // Collect stock data
            if ($message->shouldCollectStocks()) {
                $this->logger->info('Collecting stocks', ['boutique_id' => $boutiqueId]);
                $stockResult = $this->collector->collectStockData($boutique);

                if ($stockResult['success']) {
                    $this->logger->info('Stocks collected successfully', [
                        'boutique_id' => $boutiqueId,
                        'products_count' => $stockResult['products_count'],
                        'saved_count' => $stockResult['saved_count']
                    ]);
                } else {
                    $this->logger->error('Failed to collect stocks', [
                        'boutique_id' => $boutiqueId,
                        'error' => $stockResult['error']
                    ]);
                }
            }

            // Collect orders data
            if ($message->shouldCollectOrders()) {
                $this->logger->info('Collecting orders', [
                    'boutique_id' => $boutiqueId,
                    'days' => $message->getOrdersDays()
                ]);

                $ordersResult = $this->collector->collectOrdersData(
                    $boutique,
                    $message->getOrdersDays()
                );

                if ($ordersResult['success']) {
                    $this->logger->info('Orders collected successfully', [
                        'boutique_id' => $boutiqueId,
                        'saved_count' => $ordersResult['saved_count']
                    ]);
                } else {
                    $this->logger->error('Failed to collect orders', [
                        'boutique_id' => $boutiqueId,
                        'error' => $ordersResult['error']
                    ]);
                }
            }

            $this->logger->info('Data collection job completed', [
                'boutique_id' => $boutiqueId
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Exception during data collection', [
                'boutique_id' => $boutiqueId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
