<?php

namespace App\Message;

final class SyncOrdersMessage
{
    public function __construct(
        private readonly ?int $boutiqueId = null,
        private readonly int $days = 7
    ) {
    }

    public function getBoutiqueId(): ?int
    {
        return $this->boutiqueId;
    }

    public function getDays(): int
    {
        return $this->days;
    }
}
