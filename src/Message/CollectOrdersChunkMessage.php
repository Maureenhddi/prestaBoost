<?php

namespace App\Message;

class CollectOrdersChunkMessage
{
    private int $boutiqueId;
    private int $startId;
    private int $endId;

    public function __construct(int $boutiqueId, int $startId, int $endId)
    {
        $this->boutiqueId = $boutiqueId;
        $this->startId = $startId;
        $this->endId = $endId;
    }

    public function getBoutiqueId(): int
    {
        return $this->boutiqueId;
    }

    public function getStartId(): int
    {
        return $this->startId;
    }

    public function getEndId(): int
    {
        return $this->endId;
    }
}
