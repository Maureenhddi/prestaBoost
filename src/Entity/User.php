<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`users`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    private ?string $lastName = null;

    /**
     * @var Collection<int, BoutiqueUser>
     */
    #[ORM\OneToMany(targetEntity: BoutiqueUser::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $boutiqueUsers;

    public function __construct()
    {
        $this->boutiqueUsers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

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
            $boutiqueUser->setUser($this);
        }

        return $this;
    }

    public function removeBoutiqueUser(BoutiqueUser $boutiqueUser): static
    {
        if ($this->boutiqueUsers->removeElement($boutiqueUser)) {
            // set the owning side to null (unless already changed)
            if ($boutiqueUser->getUser() === $this) {
                $boutiqueUser->setUser(null);
            }
        }

        return $this;
    }

    /**
     * Check if user is SUPER_ADMIN
     */
    public function isSuperAdmin(): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $this->roles);
    }

    /**
     * Check if user is admin of a specific boutique
     */
    public function isAdminOfBoutique(Boutique $boutique): bool
    {
        foreach ($this->boutiqueUsers as $boutiqueUser) {
            if ($boutiqueUser->getBoutique() === $boutique && $boutiqueUser->getRole() === 'ADMIN') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has access to a specific boutique
     */
    public function hasAccessToBoutique(Boutique $boutique): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        foreach ($this->boutiqueUsers as $boutiqueUser) {
            if ($boutiqueUser->getBoutique() === $boutique) {
                return true;
            }
        }
        return false;
    }
}
