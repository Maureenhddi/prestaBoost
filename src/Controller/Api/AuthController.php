<?php

namespace App\Controller\Api;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\UserRepository;

class AuthController extends AbstractController
{
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        JWTTokenManagerInterface $jwtManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        $username = $data['username'] ?? $data['email'] ?? null;
        $password = $data['password'] ?? null;

        if (!$username || !$password) {
            return $this->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        $user = $userRepository->findOneBy(['email' => $username]);

        if (!$user || !$passwordHasher->isPasswordValid($user, $password)) {
            return $this->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        $token = $jwtManager->create($user);

        return $this->json([
            'token' => $token,
            'user' => [
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'roles' => $user->getRoles()
            ]
        ]);
    }
}
