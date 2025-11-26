<?php

namespace App\Message;

final class SyncStocksMessage
{
    public function __construct(
        private readonly ?int $boutiqueId = null
    ) {
    }

    public function getBoutiqueId(): ?int
    {
        return $this->boutiqueId;
    }
}
