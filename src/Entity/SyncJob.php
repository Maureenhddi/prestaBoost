<?php

namespace App\Entity;

use App\Repository\SyncJobRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SyncJobRepository::class)]
#[ORM\Table(name: 'sync_jobs')]
class SyncJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Boutique::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Boutique $boutique = null;

    #[ORM\Column(length: 50)]
    private ?string $type = null; // 'stocks', 'orders', 'both'

    #[ORM\Column(length: 50)]
    private ?string $status = null; // 'pending', 'running', 'completed', 'failed'

    #[ORM\Column]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $itemsProcessed = null;

    #[ORM\Column(nullable: true)]
    private ?int $totalItems = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(nullable: true)]
    private ?int $ordersDays = null; // 0 for all orders

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBoutique(): ?Boutique
    {
        return $this->boutique;
    }

    public function setBoutique(?Boutique $boutique): static
    {
        $this->boutique = $boutique;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getItemsProcessed(): ?int
    {
        return $this->itemsProcessed;
    }

    public function setItemsProcessed(?int $itemsProcessed): static
    {
        $this->itemsProcessed = $itemsProcessed;
        return $this;
    }

    public function getTotalItems(): ?int
    {
        return $this->totalItems;
    }

    public function setTotalItems(?int $totalItems): static
    {
        $this->totalItems = $totalItems;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getOrdersDays(): ?int
    {
        return $this->ordersDays;
    }

    public function setOrdersDays(?int $ordersDays): static
    {
        $this->ordersDays = $ordersDays;
        return $this;
    }

    public function getProgressPercentage(): int
    {
        if (!$this->totalItems || $this->totalItems === 0) {
            return 0;
        }

        return (int) (($this->itemsProcessed / $this->totalItems) * 100);
    }

    public function getDurationSeconds(): ?int
    {
        if (!$this->completedAt) {
            return $this->startedAt->diff(new \DateTimeImmutable())->s
                + $this->startedAt->diff(new \DateTimeImmutable())->i * 60
                + $this->startedAt->diff(new \DateTimeImmutable())->h * 3600;
        }

        return $this->startedAt->diff($this->completedAt)->s
            + $this->startedAt->diff($this->completedAt)->i * 60
            + $this->startedAt->diff($this->completedAt)->h * 3600;
    }
}
