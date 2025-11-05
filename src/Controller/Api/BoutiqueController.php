<?php

namespace App\Controller\Api;

use App\Entity\Boutique;
use App\Entity\BoutiqueUser;
use App\Repository\BoutiqueRepository;
use App\Repository\UserRepository;
use App\Service\BoutiqueAuthorizationService;
use App\Service\PrestaShopCollector;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/boutiques', name: 'api_boutiques_')]
#[IsGranted('ROLE_USER')]
class BoutiqueController extends AbstractController
{
    private BoutiqueRepository $boutiqueRepository;
    private EntityManagerInterface $entityManager;
    private BoutiqueAuthorizationService $authService;
    private ValidatorInterface $validator;
    private PrestaShopCollector $prestaShopCollector;
    private LoggerInterface $logger;

    public function __construct(
        BoutiqueRepository $boutiqueRepository,
        EntityManagerInterface $entityManager,
        BoutiqueAuthorizationService $authService,
        ValidatorInterface $validator,
        PrestaShopCollector $prestaShopCollector,
        LoggerInterface $logger
    ) {
        $this->boutiqueRepository = $boutiqueRepository;
        $this->entityManager = $entityManager;
        $this->authService = $authService;
        $this->validator = $validator;
        $this->prestaShopCollector = $prestaShopCollector;
        $this->logger = $logger;
    }

    /**
     * Get all boutiques accessible by the current user
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();

        if ($user->isSuperAdmin()) {
            $boutiques = $this->boutiqueRepository->findAll();
        } else {
            $boutiques = [];
            foreach ($user->getBoutiqueUsers() as $boutiqueUser) {
                $boutiques[] = $boutiqueUser->getBoutique();
            }
        }

        $data = array_map(function (Boutique $boutique) use ($user) {
            return [
                'id' => $boutique->getId(),
                'name' => $boutique->getName(),
                'domain' => $boutique->getDomain(),
                'logo_url' => $boutique->getLogoUrl(),
                'favicon_url' => $boutique->getFaviconUrl(),
                'theme_color' => $boutique->getThemeColor(),
                'font_family' => $boutique->getFontFamily(),
                'is_admin' => $this->authService->isAdminOfBoutique($user, $boutique),
                'created_at' => $boutique->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }, $boutiques);

        return $this->json([
            'success' => true,
            'data' => $data,
            'count' => count($data)
        ]);
    }

    /**
     * Create a new boutique (user becomes admin)
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        // Create boutique
        $boutique = new Boutique();
        $boutique->setName($data['name'] ?? '');
        $boutique->setDomain($data['domain'] ?? '');
        $boutique->setApiKey($data['api_key'] ?? '');

        // Validate
        $errors = $this->validator->validate($boutique);
        if (count($errors) > 0) {
            return $this->json([
                'success' => false,
                'errors' => (string) $errors
            ], 400);
        }

        // Save boutique
        $this->entityManager->persist($boutique);

        // Create boutique-user relationship (creator becomes admin)
        $boutiqueUser = new BoutiqueUser();
        $boutiqueUser->setBoutique($boutique);
        $boutiqueUser->setUser($user);
        $boutiqueUser->setRole('ADMIN');

        $this->entityManager->persist($boutiqueUser);
        $this->entityManager->flush();

        // Automatically collect stock data for the new boutique
        $collectionResult = null;
        try {
            $this->logger->info('Auto-collecting stock data for new boutique', [
                'boutique_id' => $boutique->getId(),
                'boutique_name' => $boutique->getName()
            ]);

            $collectionResult = $this->prestaShopCollector->collectStockData($boutique);

            if ($collectionResult['success']) {
                $this->logger->info('Stock data collected successfully', [
                    'boutique_id' => $boutique->getId(),
                    'saved_count' => $collectionResult['saved_count']
                ]);
            } else {
                $this->logger->warning('Stock data collection failed', [
                    'boutique_id' => $boutique->getId(),
                    'error' => $collectionResult['error'] ?? 'Unknown error'
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error during auto stock collection', [
                'boutique_id' => $boutique->getId(),
                'error' => $e->getMessage()
            ]);
        }

        return $this->json([
            'success' => true,
            'data' => [
                'id' => $boutique->getId(),
                'name' => $boutique->getName(),
                'domain' => $boutique->getDomain(),
                'stock_collected' => $collectionResult['success'] ?? false,
                'stocks_count' => $collectionResult['saved_count'] ?? 0,
            ],
            'message' => 'Boutique créée avec succès' .
                ($collectionResult['success'] ?? false ?
                    ' et ' . ($collectionResult['saved_count'] ?? 0) . ' produits collectés.' :
                    '. Collecte des stocks échouée, vous pouvez la relancer manuellement.')
        ], 201);
    }

    /**
     * Get boutique details
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->getUser();
        $boutique = $this->boutiqueRepository->find($id);

        if (!$boutique) {
            return $this->json(['error' => 'Boutique not found'], 404);
        }

        $this->authService->denyAccessUnlessGranted($user, $boutique);

        return $this->json([
            'success' => true,
            'data' => [
                'id' => $boutique->getId(),
                'name' => $boutique->getName(),
                'domain' => $boutique->getDomain(),
                'logo_url' => $boutique->getLogoUrl(),
                'favicon_url' => $boutique->getFaviconUrl(),
                'theme_color' => $boutique->getThemeColor(),
                'font_family' => $boutique->getFontFamily(),
                'custom_css' => $boutique->getCustomCss(),
                'is_admin' => $this->authService->isAdminOfBoutique($user, $boutique),
                'created_at' => $boutique->getCreatedAt()->format('Y-m-d H:i:s'),
            ]
        ]);
    }

    /**
     * Invite a user to a boutique
     */
    #[Route('/{id}/invite', name: 'invite', methods: ['POST'])]
    public function invite(int $id, Request $request, UserRepository $userRepository): JsonResponse
    {
        $currentUser = $this->getUser();
        $boutique = $this->boutiqueRepository->find($id);

        if (!$boutique) {
            return $this->json(['error' => 'Boutique not found'], 404);
        }

        // Must be admin to invite
        $this->authService->denyAccessUnlessAdmin($currentUser, $boutique);

        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;
        $role = $data['role'] ?? 'USER';

        if (!$email) {
            return $this->json(['error' => 'Email is required'], 400);
        }

        if (!in_array($role, ['USER', 'ADMIN'])) {
            return $this->json(['error' => 'Invalid role'], 400);
        }

        // Find user by email
        $invitedUser = $userRepository->findOneBy(['email' => $email]);
        if (!$invitedUser) {
            return $this->json(['error' => 'User not found'], 404);
        }

        // Check if already has access
        if ($invitedUser->hasAccessToBoutique($boutique)) {
            return $this->json(['error' => 'User already has access to this boutique'], 400);
        }

        // Create relationship
        $boutiqueUser = new BoutiqueUser();
        $boutiqueUser->setBoutique($boutique);
        $boutiqueUser->setUser($invitedUser);
        $boutiqueUser->setRole($role);

        $this->entityManager->persist($boutiqueUser);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'User invited successfully'
        ]);
    }
}
