<?php

namespace App\MessageHandler;

use App\Message\CollectOrdersChunkMessage;
use App\Repository\BoutiqueRepository;
use App\Service\PrestaShopCollector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class CollectOrdersChunkMessageHandler
{
    public function __construct(
        private BoutiqueRepository $boutiqueRepository,
        private PrestaShopCollector $collector,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(CollectOrdersChunkMessage $message): void
    {
        $boutiqueId = $message->getBoutiqueId();
        $startId = $message->getStartId();
        $endId = $message->getEndId();

        $this->logger->info('Processing orders chunk', [
            'boutique_id' => $boutiqueId,
            'start_id' => $startId,
            'end_id' => $endId,
            'chunk_size' => $endId - $startId + 1
        ]);

        $boutique = $this->boutiqueRepository->find($boutiqueId);

        if (!$boutique) {
            $this->logger->error('Boutique not found for chunk collection', [
                'boutique_id' => $boutiqueId
            ]);
            return;
        }

        try {
            $result = $this->collector->collectOrdersChunk($boutique, $startId, $endId);

            if ($result['success']) {
                $this->logger->info('Orders chunk collected successfully', [
                    'boutique_id' => $boutiqueId,
                    'start_id' => $startId,
                    'end_id' => $endId,
                    'orders_found' => $result['orders_found'],
                    'saved_count' => $result['saved_count']
                ]);
            } else {
                $this->logger->error('Failed to collect orders chunk', [
                    'boutique_id' => $boutiqueId,
                    'start_id' => $startId,
                    'end_id' => $endId,
                    'error' => $result['error']
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Exception during chunk collection', [
                'boutique_id' => $boutiqueId,
                'start_id' => $startId,
                'end_id' => $endId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
