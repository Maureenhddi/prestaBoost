<?php

namespace App\Controller;

use App\Entity\Boutique;
use App\Entity\BoutiqueUser;
use App\Entity\User;
use App\Repository\BoutiqueRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/users')]
class UserManagementController extends AbstractController
{
    #[Route('', name: 'app_users')]
    public function index(UserRepository $userRepository): Response
    {
        $users = $userRepository->findAll();

        return $this->render('users/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/new', name: 'app_user_new')]
    public function new(BoutiqueRepository $boutiqueRepository): Response
    {
        $boutiques = $boutiqueRepository->findAll();

        return $this->render('users/new.html.twig', [
            'boutiques' => $boutiques,
        ]);
    }

    #[Route('/create', name: 'app_user_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        BoutiqueRepository $boutiqueRepository
    ): Response {
        $email = $request->request->get('email');
        $firstName = $request->request->get('firstName');
        $lastName = $request->request->get('lastName');
        $password = $request->request->get('password');
        $isSuperAdmin = $request->request->get('isSuperAdmin') === '1';
        $boutiqueIds = $request->request->all('boutiques') ?? [];

        // Validation
        if (!$email || !$firstName || !$lastName || !$password) {
            $this->addFlash('error', 'Tous les champs sont requis.');
            return $this->redirectToRoute('app_user_new');
        }

        // Check if email already exists
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            $this->addFlash('error', 'Un utilisateur avec cet email existe déjà.');
            return $this->redirectToRoute('app_user_new');
        }

        // Create user
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $roles = ['ROLE_USER'];
        if ($isSuperAdmin) {
            $roles[] = 'ROLE_SUPER_ADMIN';
        }
        $user->setRoles($roles);

        $entityManager->persist($user);

        // Assign boutiques to user
        foreach ($boutiqueIds as $boutiqueData) {
            $parts = explode(':', $boutiqueData);
            $boutiqueId = (int) $parts[0];
            $role = $parts[1] ?? 'USER';

            $boutique = $boutiqueRepository->find($boutiqueId);
            if ($boutique) {
                $boutiqueUser = new BoutiqueUser();
                $boutiqueUser->setUser($user);
                $boutiqueUser->setBoutique($boutique);
                $boutiqueUser->setRole($role);
                $entityManager->persist($boutiqueUser);
            }
        }

        $entityManager->flush();

        $this->addFlash('success', 'Utilisateur créé avec succès !');
        return $this->redirectToRoute('app_users');
    }

    #[Route('/{id}/edit', name: 'app_user_edit')]
    public function edit(int $id, UserRepository $userRepository, BoutiqueRepository $boutiqueRepository): Response
    {
        $user = $userRepository->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Utilisateur non trouvé');
        }

        $boutiques = $boutiqueRepository->findAll();

        return $this->render('users/edit.html.twig', [
            'user' => $user,
            'boutiques' => $boutiques,
        ]);
    }

    #[Route('/{id}/update', name: 'app_user_update', methods: ['POST'])]
    public function update(
        int $id,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        BoutiqueRepository $boutiqueRepository
    ): Response {
        $user = $userRepository->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Utilisateur non trouvé');
        }

        $email = $request->request->get('email');
        $firstName = $request->request->get('firstName');
        $lastName = $request->request->get('lastName');
        $password = $request->request->get('password');
        $isSuperAdmin = $request->request->get('isSuperAdmin') === '1';
        $boutiqueIds = $request->request->all('boutiques') ?? [];

        // Validation
        if (!$email || !$firstName || !$lastName) {
            $this->addFlash('error', 'Tous les champs sont requis.');
            return $this->redirectToRoute('app_user_edit', ['id' => $id]);
        }

        // Check if email already exists (except for current user)
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser && $existingUser->getId() !== $user->getId()) {
            $this->addFlash('error', 'Un utilisateur avec cet email existe déjà.');
            return $this->redirectToRoute('app_user_edit', ['id' => $id]);
        }

        // Update user
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);

        // Update password only if provided
        if ($password) {
            $user->setPassword($passwordHasher->hashPassword($user, $password));
        }

        $roles = ['ROLE_USER'];
        if ($isSuperAdmin) {
            $roles[] = 'ROLE_SUPER_ADMIN';
        }
        $user->setRoles($roles);

        // Remove existing boutique assignments
        foreach ($user->getBoutiqueUsers() as $boutiqueUser) {
            $entityManager->remove($boutiqueUser);
        }

        // Assign new boutiques
        foreach ($boutiqueIds as $boutiqueData) {
            $parts = explode(':', $boutiqueData);
            $boutiqueId = (int) $parts[0];
            $role = $parts[1] ?? 'USER';

            $boutique = $boutiqueRepository->find($boutiqueId);
            if ($boutique) {
                $boutiqueUser = new BoutiqueUser();
                $boutiqueUser->setUser($user);
                $boutiqueUser->setBoutique($boutique);
                $boutiqueUser->setRole($role);
                $entityManager->persist($boutiqueUser);
            }
        }

        $entityManager->flush();

        $this->addFlash('success', 'Utilisateur mis à jour avec succès !');
        return $this->redirectToRoute('app_users');
    }

    #[Route('/{id}/delete', name: 'app_user_delete', methods: ['POST'])]
    public function delete(
        int $id,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $userRepository->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Utilisateur non trouvé');
        }

        // Prevent deleting yourself
        if ($user->getId() === $this->getUser()->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            return $this->redirectToRoute('app_users');
        }

        $entityManager->remove($user);
        $entityManager->flush();

        $this->addFlash('success', 'Utilisateur supprimé avec succès !');
        return $this->redirectToRoute('app_users');
    }
}
