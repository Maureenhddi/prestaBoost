<?php

namespace App\Entity;

use App\Repository\BoutiqueUserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BoutiqueUserRepository::class)]
#[ORM\Table(name: 'boutique_users')]
#[ORM\UniqueConstraint(name: 'boutique_user_unique', columns: ['boutique_id', 'user_id'])]
class BoutiqueUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'boutiqueUsers')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Boutique $boutique = null;

    #[ORM\ManyToOne(inversedBy: 'boutiqueUsers')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /**
     * Role in this boutique: USER or ADMIN
     */
    #[ORM\Column(length: 20)]
    private ?string $role = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->role = 'USER'; // Default role
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

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
}
