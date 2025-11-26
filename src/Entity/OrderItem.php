<?php

namespace App\Entity;

use App\Repository\OrderItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
#[ORM\Table(name: '`order_items`')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Order $order = null;

    #[ORM\Column]
    private ?int $productId = null;

    #[ORM\Column(length: 255)]
    private ?string $productName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $productReference = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $unitPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $totalPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $wholesalePrice = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): static
    {
        $this->order = $order;

        return $this;
    }

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function setProductId(int $productId): static
    {
        $this->productId = $productId;

        return $this;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function setProductName(string $productName): static
    {
        $this->productName = $productName;

        return $this;
    }

    public function getProductReference(): ?string
    {
        return $this->productReference;
    }

    public function setProductReference(?string $productReference): static
    {
        $this->productReference = $productReference;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitPrice(): ?string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): static
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    public function getTotalPrice(): ?string
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(string $totalPrice): static
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }

    public function getWholesalePrice(): ?string
    {
        return $this->wholesalePrice;
    }

    public function setWholesalePrice(?string $wholesalePrice): static
    {
        $this->wholesalePrice = $wholesalePrice;

        return $this;
    }

    /**
     * Calculate the profit margin for this item
     * Returns the margin as a percentage (0-100)
     */
    public function getMarginPercent(): ?float
    {
        if (!$this->wholesalePrice || !$this->unitPrice) {
            return null;
        }

        $wholesale = (float) $this->wholesalePrice;
        $selling = (float) $this->unitPrice;

        if ($selling <= 0) {
            return null;
        }

        return round((($selling - $wholesale) / $selling) * 100, 1);
    }

    /**
     * Calculate the profit amount for this item (per unit)
     */
    public function getProfitPerUnit(): ?float
    {
        if (!$this->wholesalePrice || !$this->unitPrice) {
            return null;
        }

        return round((float) $this->unitPrice - (float) $this->wholesalePrice, 2);
    }

    /**
     * Calculate the total profit for this item (profit per unit * quantity)
     */
    public function getTotalProfit(): ?float
    {
        $profitPerUnit = $this->getProfitPerUnit();

        if ($profitPerUnit === null || !$this->quantity) {
            return null;
        }

        return round($profitPerUnit * $this->quantity, 2);
    }
}
