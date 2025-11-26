<?php

namespace App\Controller\Api;

use App\Repository\BoutiqueRepository;
use App\Repository\OrderRepository;
use App\Service\BoutiqueAuthorizationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/orders')]
#[IsGranted('ROLE_USER')]
class OrderApiController extends AbstractController
{
    #[Route('/boutique/{id}/statistics', name: 'api_boutique_orders_statistics', methods: ['GET'])]
    public function statistics(
        int $id,
        Request $request,
        BoutiqueRepository $boutiqueRepository,
        OrderRepository $orderRepository,
        BoutiqueAuthorizationService $authService
    ): JsonResponse {
        $boutique = $boutiqueRepository->find($id);

        if (!$boutique) {
            return $this->json(['error' => 'Boutique non trouvée'], 404);
        }

        $user = $this->getUser();
        try {
            $authService->denyAccessUnlessGranted($user, $boutique);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $comparePeriod = $request->query->get('compare', 'lastweek');

        // Get date ranges for current and comparison periods
        $now = new \DateTimeImmutable();
        $periodDates = $this->getPeriodDates($comparePeriod, $now);

        if (!$periodDates) {
            return $this->json(['error' => 'Période de comparaison invalide'], 400);
        }

        $currentStart = $periodDates['currentStart'];
        $currentEnd = $now;
        $previousStart = $periodDates['previousStart'];
        $previousEnd = $periodDates['previousEnd'];

        // Get all statistics in ONE optimized query (4 queries -> 1 query)
        $stats = $orderRepository->getStatistics(
            $boutique->getId(),
            $currentStart,
            $currentEnd,
            $previousStart,
            $previousEnd
        );

        // Calculate variations
        $revenueVariation = $stats['previousRevenue'] > 0
            ? round((($stats['currentRevenue'] - $stats['previousRevenue']) / $stats['previousRevenue']) * 100, 1)
            : 0;

        $orderCountVariation = $stats['previousOrderCount'] > 0
            ? round((($stats['currentOrderCount'] - $stats['previousOrderCount']) / $stats['previousOrderCount']) * 100, 1)
            : 0;

        // Get recent orders (still separate query but optimized with LIMIT)
        $recentOrders = $orderRepository->getRecentOrders($boutique->getId(), 10);

        // Format recent orders for JSON
        $formattedOrders = array_map(function($order) {
            return [
                'id' => $order->getId(),
                'orderId' => $order->getOrderId(),
                'reference' => $order->getReference(),
                'totalPaid' => $order->getTotalPaid(),
                'currentState' => $order->getCurrentState(),
                'payment' => $order->getPayment(),
                'orderDate' => $order->getOrderDate()->format('Y-m-d H:i:s'),
            ];
        }, $recentOrders);

        $response = $this->json([
            'success' => true,
            'current' => [
                'revenue' => $stats['currentRevenue'],
                'orderCount' => $stats['currentOrderCount'],
                'startDate' => $currentStart->format('Y-m-d'),
                'endDate' => $currentEnd->format('Y-m-d'),
            ],
            'previous' => [
                'revenue' => $stats['previousRevenue'],
                'orderCount' => $stats['previousOrderCount'],
                'startDate' => $previousStart->format('Y-m-d'),
                'endDate' => $previousEnd->format('Y-m-d'),
            ],
            'variations' => [
                'revenue' => $revenueVariation,
                'orderCount' => $orderCountVariation,
            ],
            'recentOrders' => $formattedOrders,
        ]);

        // Cache for 2 minutes to improve performance
        $response->setSharedMaxAge(120);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }

    private function getPeriodDates(string $period, \DateTimeImmutable $now): ?array
    {
        return match($period) {
            'yesterday' => [
                'currentStart' => $now->modify('-1 day')->setTime(0, 0, 0),
                'previousStart' => $now->modify('-2 days')->setTime(0, 0, 0),
                'previousEnd' => $now->modify('-1 day')->setTime(23, 59, 59),
            ],
            'lastweek' => [
                'currentStart' => $now->modify('-7 days'),
                'previousStart' => $now->modify('-14 days'),
                'previousEnd' => $now->modify('-7 days'),
            ],
            'lastmonth' => [
                'currentStart' => $now->modify('-1 month'),
                'previousStart' => $now->modify('-2 months'),
                'previousEnd' => $now->modify('-1 month'),
            ],
            default => null,
        };
    }
}
