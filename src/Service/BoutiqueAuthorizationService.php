<?php

namespace App\Service;

use App\Entity\Boutique;
use App\Entity\User;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class BoutiqueAuthorizationService
{
    /**
     * Check if user has access to boutique
     */
    public function canAccessBoutique(User $user, Boutique $boutique): bool
    {
        return $user->hasAccessToBoutique($boutique);
    }

    /**
     * Check if user is admin of boutique
     */
    public function isAdminOfBoutique(User $user, Boutique $boutique): bool
    {
        return $user->isSuperAdmin() || $user->isAdminOfBoutique($boutique);
    }

    /**
     * Ensure user has access to boutique or throw exception
     */
    public function denyAccessUnlessGranted(User $user, Boutique $boutique): void
    {
        if (!$this->canAccessBoutique($user, $boutique)) {
            throw new AccessDeniedException('You do not have access to this boutique.');
        }
    }

    /**
     * Ensure user is admin of boutique or throw exception
     */
    public function denyAccessUnlessAdmin(User $user, Boutique $boutique): void
    {
        if (!$this->isAdminOfBoutique($user, $boutique)) {
            throw new AccessDeniedException('You must be an admin of this boutique.');
        }
    }
}
