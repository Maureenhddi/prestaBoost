<?php

namespace App\Controller\Api;

use App\Repository\BoutiqueRepository;
use App\Repository\DailyStockRepository;
use App\Service\BoutiqueAuthorizationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/stocks', name: 'api_stocks_')]
#[IsGranted('ROLE_USER')]
class StockController extends AbstractController
{
    private DailyStockRepository $dailyStockRepository;
    private BoutiqueRepository $boutiqueRepository;
    private BoutiqueAuthorizationService $authService;

    public function __construct(
        DailyStockRepository $dailyStockRepository,
        BoutiqueRepository $boutiqueRepository,
        BoutiqueAuthorizationService $authService
    ) {
        $this->dailyStockRepository = $dailyStockRepository;
        $this->boutiqueRepository = $boutiqueRepository;
        $this->authService = $authService;
    }

    /**
     * Get latest stock snapshot for all accessible boutiques or a specific one
     */
    #[Route('/latest', name: 'latest', methods: ['GET'])]
    public function getLatestStocks(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $boutiqueId = $request->query->get('boutique_id');

        $boutique = null;
        if ($boutiqueId) {
            $boutique = $this->boutiqueRepository->find($boutiqueId);
            if (!$boutique) {
                return $this->json(['error' => 'Boutique not found'], 404);
            }

            // Check access
            $this->authService->denyAccessUnlessGranted($user, $boutique);
        }

        $stocks = $this->dailyStockRepository->findLatestSnapshot($boutique);

        // Filter by accessible boutiques if not super admin
        if (!$user->isSuperAdmin() && !$boutique) {
            $stocks = array_filter($stocks, function ($stock) use ($user) {
                return $user->hasAccessToBoutique($stock->getBoutique());
            });
        }

        // Format response
        $data = [];
        foreach ($stocks as $stock) {
            $data[] = [
                'id' => $stock->getId(),
                'boutique' => [
                    'id' => $stock->getBoutique()->getId(),
                    'name' => $stock->getBoutique()->getName(),
                ],
                'product_id' => $stock->getProductId(),
                'reference' => $stock->getReference(),
                'name' => $stock->getName(),
                'quantity' => $stock->getQuantity(),
                'collected_at' => $stock->getCollectedAt()->format('Y-m-d H:i:s'),
            ];
        }

        return $this->json([
            'success' => true,
            'data' => $data,
            'count' => count($data)
        ]);
    }

    /**
     * Get stock history for a specific product
     */
    #[Route('/history/{boutiqueId}/{productId}', name: 'history', methods: ['GET'])]
    public function getStockHistory(int $boutiqueId, int $productId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $days = $request->query->getInt('days', 30);

        $boutique = $this->boutiqueRepository->find($boutiqueId);
        if (!$boutique) {
            return $this->json(['error' => 'Boutique not found'], 404);
        }

        // Check access
        $this->authService->denyAccessUnlessGranted($user, $boutique);

        $history = $this->dailyStockRepository->findProductHistory($boutique, $productId, $days);

        $data = array_map(function ($stock) {
            return [
                'quantity' => $stock->getQuantity(),
                'collected_at' => $stock->getCollectedAt()->format('Y-m-d H:i:s'),
            ];
        }, $history);

        return $this->json([
            'success' => true,
            'boutique_id' => $boutiqueId,
            'product_id' => $productId,
            'data' => $data,
            'count' => count($data)
        ]);
    }
}
