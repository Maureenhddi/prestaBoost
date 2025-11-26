<?php

namespace App\MessageHandler;

use App\Message\SyncOrdersMessage;
use App\Repository\BoutiqueRepository;
use App\Repository\OrderRepository;
use App\Service\PrestaShopCollector;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

#[AsMessageHandler(fromTransport: 'async')]
final class SyncOrdersHandler
{
    public function __construct(
        private readonly PrestaShopCollector $collector,
        private readonly BoutiqueRepository $boutiqueRepository,
        private readonly OrderRepository $orderRepository,
        private readonly HubInterface $hub,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug = false
    ) {
    }

    public function __invoke(SyncOrdersMessage $message): void
    {
        $this->logger->info('[Sync Orders] Starting sync', [
            'boutique_id' => $message->getBoutiqueId(),
            'days' => $message->getDays()
        ]);

        try {
            $boutiques = $message->getBoutiqueId()
                ? [$this->boutiqueRepository->find($message->getBoutiqueId())]
                : $this->boutiqueRepository->findAll();

            foreach ($boutiques as $boutique) {
                if (!$boutique) {
                    continue;
                }

                $this->logger->info('[Sync Orders] Syncing boutique', ['boutique' => $boutique->getName()]);

                // Calculate date range for stats
                $endDate = new \DateTime();
                $startDate = (clone $endDate)->modify('-' . $message->getDays() . ' days');

                // Get order count BEFORE sync
                $orderCountBefore = $this->orderRepository->countOrders($boutique->getId(), $startDate, $endDate);
                $revenueBefore = (float) $this->orderRepository->getTotalRevenue($boutique->getId(), $startDate, $endDate);

                // Perform sync
                $ordersCollected = $this->collector->collectOrdersData($boutique, $message->getDays());

                // Get order count AFTER sync
                $orderCountAfter = $this->orderRepository->countOrders($boutique->getId(), $startDate, $endDate);
                $revenueAfter = (float) $this->orderRepository->getTotalRevenue($boutique->getId(), $startDate, $endDate);

                $newOrdersCount = $orderCountAfter - $orderCountBefore;

                $this->logger->info('[Sync Orders] Sync completed', [
                    'boutique' => $boutique->getName(),
                    'orders_collected' => $ordersCollected,
                    'new_orders' => $newOrdersCount,
                    'total_orders' => $orderCountAfter,
                    'revenue_before' => $revenueBefore,
                    'revenue_after' => $revenueAfter
                ]);

                // If new orders were detected, broadcast Turbo Stream update
                if ($newOrdersCount > 0) {
                    $this->broadcastDashboardUpdate($boutique->getId(), $orderCountAfter, $revenueAfter);
                }
            }

            $this->logger->info('[Sync Orders] All boutiques synced');
        } catch (\Exception $e) {
            $this->logger->error('[Sync Orders] Error during sync', [
                'error' => $e->getMessage(),
                'trace' => $this->debug ? $e->getTraceAsString() : null
            ]);
            throw $e; // Re-throw to let Messenger handle retries
        }
    }

    private function broadcastDashboardUpdate(int $boutiqueId, int $orderCount, float $revenue): void
    {
        try {
            // Render the Turbo Stream update
            $html = $this->twig->render('dashboard/_stats_update.stream.html.twig', [
                'boutiqueId' => $boutiqueId,
                'orderCount' => $orderCount,
                'revenue' => $revenue
            ]);

            // Broadcast to all connected clients watching this boutique
            $update = new Update(
                "dashboard/boutique/{$boutiqueId}",
                $html,
                false
            );

            $this->hub->publish($update);

            $this->logger->info('[Turbo Stream] Dashboard update broadcasted', [
                'boutique_id' => $boutiqueId,
                'order_count' => $orderCount,
                'revenue' => $revenue
            ]);
        } catch (\Exception $e) {
            // Don't fail the whole sync if broadcast fails
            $this->logger->error('[Turbo Stream] Failed to broadcast update', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
