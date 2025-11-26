<?php

namespace App\Entity;

use App\Repository\ProductSupplierRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductSupplierRepository::class)]
#[ORM\Table(name: 'product_suppliers')]
#[ORM\Index(columns: ['product_id'], name: 'idx_product_id')]
#[ORM\Index(columns: ['supplier_id'], name: 'idx_supplier_id')]
class ProductSupplier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Boutique $boutique = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Supplier $supplier = null;

    #[ORM\Column]
    private ?int $productId = null;

    #[ORM\Column(length: 255)]
    private ?string $productName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $productReference = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $supplierReference = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $wholesalePrice = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $minimumOrderQuantity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $discountPercent = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $discountThreshold = null;

    #[ORM\Column]
    private ?bool $isPreferred = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastPurchaseDate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $lastPurchasePrice = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

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

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(?Supplier $supplier): static
    {
        $this->supplier = $supplier;
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

    public function getSupplierReference(): ?string
    {
        return $this->supplierReference;
    }

    public function setSupplierReference(?string $supplierReference): static
    {
        $this->supplierReference = $supplierReference;
        return $this;
    }

    public function getWholesalePrice(): ?string
    {
        return $this->wholesalePrice;
    }

    public function setWholesalePrice(string $wholesalePrice): static
    {
        $this->wholesalePrice = $wholesalePrice;
        return $this;
    }

    public function getMinimumOrderQuantity(): ?int
    {
        return $this->minimumOrderQuantity;
    }

    public function setMinimumOrderQuantity(?int $minimumOrderQuantity): static
    {
        $this->minimumOrderQuantity = $minimumOrderQuantity;
        return $this;
    }

    public function getDiscountPercent(): ?string
    {
        return $this->discountPercent;
    }

    public function setDiscountPercent(?string $discountPercent): static
    {
        $this->discountPercent = $discountPercent;
        return $this;
    }

    public function getDiscountThreshold(): ?int
    {
        return $this->discountThreshold;
    }

    public function setDiscountThreshold(?int $discountThreshold): static
    {
        $this->discountThreshold = $discountThreshold;
        return $this;
    }

    public function isPreferred(): ?bool
    {
        return $this->isPreferred;
    }

    public function setPreferred(bool $isPreferred): static
    {
        $this->isPreferred = $isPreferred;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getLastPurchaseDate(): ?\DateTimeImmutable
    {
        return $this->lastPurchaseDate;
    }

    public function setLastPurchaseDate(?\DateTimeImmutable $lastPurchaseDate): static
    {
        $this->lastPurchaseDate = $lastPurchaseDate;
        return $this;
    }

    public function getLastPurchasePrice(): ?string
    {
        return $this->lastPurchasePrice;
    }

    public function setLastPurchasePrice(?string $lastPurchasePrice): static
    {
        $this->lastPurchasePrice = $lastPurchasePrice;
        return $this;
    }
}
