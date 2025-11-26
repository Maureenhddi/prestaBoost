<?php

namespace App\MessageHandler;

use App\Message\SyncStocksMessage;
use App\Repository\BoutiqueRepository;
use App\Service\PrestaShopCollector;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'async')]
final class SyncStocksHandler
{
    public function __construct(
        private readonly PrestaShopCollector $collector,
        private readonly BoutiqueRepository $boutiqueRepository,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug = false
    ) {
    }

    public function __invoke(SyncStocksMessage $message): void
    {
        $this->logger->info('[Sync Stocks] Starting sync', [
            'boutique_id' => $message->getBoutiqueId()
        ]);

        try {
            $boutiques = $message->getBoutiqueId()
                ? [$this->boutiqueRepository->find($message->getBoutiqueId())]
                : $this->boutiqueRepository->findAll();

            foreach ($boutiques as $boutique) {
                if (!$boutique) {
                    continue;
                }

                $this->logger->info('[Sync Stocks] Syncing boutique', ['boutique' => $boutique->getName()]);

                // Perform sync
                $productsCollected = $this->collector->collectStockData($boutique);

                $this->logger->info('[Sync Stocks] Sync completed', [
                    'boutique' => $boutique->getName(),
                    'products_collected' => $productsCollected
                ]);
            }

            $this->logger->info('[Sync Stocks] All boutiques synced');
        } catch (\Exception $e) {
            $this->logger->error('[Sync Stocks] Error during sync', [
                'error' => $e->getMessage(),
                'trace' => $this->debug ? $e->getTraceAsString() : null
            ]);
            throw $e; // Re-throw to let Messenger handle retries
        }
    }
}
