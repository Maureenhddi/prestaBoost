<?php

namespace App\EventListener;

use App\Entity\Boutique;
use App\Message\CollectBoutiqueDataMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;
use Psr\Log\LoggerInterface;

#[AsEntityListener(event: Events::postPersist, entity: Boutique::class)]
class BoutiqueListener
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger
    ) {
    }

    public function postPersist(Boutique $boutique, PostPersistEventArgs $event): void
    {
        $this->logger->info('New boutique created, scheduling data collection', [
            'boutique_id' => $boutique->getId(),
            'boutique_name' => $boutique->getName()
        ]);

        // Dispatch async message to collect data
        $this->messageBus->dispatch(new CollectBoutiqueDataMessage(
            $boutique->getId(),
            true, // collect stocks
            true, // collect orders
            30    // last 30 days
        ));

        $this->logger->info('Data collection job dispatched for new boutique', [
            'boutique_id' => $boutique->getId()
        ]);
    }
}
