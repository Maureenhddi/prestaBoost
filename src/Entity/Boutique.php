<?php

namespace App\Entity;

use App\Repository\BoutiqueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BoutiqueRepository::class)]
#[ORM\Table(name: 'boutiques')]
class Boutique
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $domain = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $apiKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $faviconUrl = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $themeColor = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $primaryColor = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fontFamily = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $customCss = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 10])]
    private int $lowStockThreshold = 10;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, BoutiqueUser>
     */
    #[ORM\OneToMany(targetEntity: BoutiqueUser::class, mappedBy: 'boutique', orphanRemoval: true)]
    private Collection $boutiqueUsers;

    /**
     * @var Collection<int, DailyStock>
     */
    #[ORM\OneToMany(targetEntity: DailyStock::class, mappedBy: 'boutique', orphanRemoval: true)]
    private Collection $dailyStocks;

    public function __construct()
    {
        $this->boutiqueUsers = new ArrayCollection();
        $this->dailyStocks = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function setLogoUrl(?string $logoUrl): static
    {
        $this->logoUrl = $logoUrl;

        return $this;
    }

    public function getFaviconUrl(): ?string
    {
        return $this->faviconUrl;
    }

    public function setFaviconUrl(?string $faviconUrl): static
    {
        $this->faviconUrl = $faviconUrl;

        return $this;
    }

    public function getThemeColor(): ?string
    {
        return $this->themeColor;
    }

    public function setThemeColor(?string $themeColor): static
    {
        $this->themeColor = $themeColor;

        return $this;
    }

    public function getPrimaryColor(): ?string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(?string $primaryColor): static
    {
        $this->primaryColor = $primaryColor;

        return $this;
    }

    public function getFontFamily(): ?string
    {
        return $this->fontFamily;
    }

    public function setFontFamily(?string $fontFamily): static
    {
        $this->fontFamily = $fontFamily;

        return $this;
    }

    public function getCustomCss(): ?string
    {
        return $this->customCss;
    }

    public function setCustomCss(?string $customCss): static
    {
        $this->customCss = $customCss;

        return $this;
    }

    public function getLowStockThreshold(): int
    {
        return $this->lowStockThreshold;
    }

    public function setLowStockThreshold(int $lowStockThreshold): static
    {
        $this->lowStockThreshold = max(1, min(100, $lowStockThreshold)); // Between 1 and 100

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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return Collection<int, BoutiqueUser>
     */
    public function getBoutiqueUsers(): Collection
    {
        return $this->boutiqueUsers;
    }

    public function addBoutiqueUser(BoutiqueUser $boutiqueUser): static
    {
        if (!$this->boutiqueUsers->contains($boutiqueUser)) {
            $this->boutiqueUsers->add($boutiqueUser);
            $boutiqueUser->setBoutique($this);
        }

        return $this;
    }

    public function removeBoutiqueUser(BoutiqueUser $boutiqueUser): static
    {
        if ($this->boutiqueUsers->removeElement($boutiqueUser)) {
            // set the owning side to null (unless already changed)
            if ($boutiqueUser->getBoutique() === $this) {
                $boutiqueUser->setBoutique(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, DailyStock>
     */
    public function getDailyStocks(): Collection
    {
        return $this->dailyStocks;
    }

    public function addDailyStock(DailyStock $dailyStock): static
    {
        if (!$this->dailyStocks->contains($dailyStock)) {
            $this->dailyStocks->add($dailyStock);
            $dailyStock->setBoutique($this);
        }

        return $this;
    }

    public function removeDailyStock(DailyStock $dailyStock): static
    {
        if ($this->dailyStocks->removeElement($dailyStock)) {
            // set the owning side to null (unless already changed)
            if ($dailyStock->getBoutique() === $this) {
                $dailyStock->setBoutique(null);
            }
        }

        return $this;
    }
}
