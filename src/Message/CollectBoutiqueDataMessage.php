<?php

namespace App\Message;

class CollectBoutiqueDataMessage
{
    public function __construct(
        private int $boutiqueId,
        private bool $collectStocks = true,
        private bool $collectOrders = false,
        private int $ordersDays = 30,
        private ?int $syncJobId = null
    ) {
    }

    public function getBoutiqueId(): int
    {
        return $this->boutiqueId;
    }

    public function shouldCollectStocks(): bool
    {
        return $this->collectStocks;
    }

    public function shouldCollectOrders(): bool
    {
        return $this->collectOrders;
    }

    public function getOrdersDays(): int
    {
        return $this->ordersDays;
    }

    public function getSyncJobId(): ?int
    {
        return $this->syncJobId;
    }
}
