<?php

namespace App\Controller;

use App\Repository\DailyStockRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductSupplierRepository;
use App\Service\ReplenishmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProductController extends AbstractController
{
    public function __construct(
        private DailyStockRepository $dailyStockRepository,
        private OrderRepository $orderRepository,
        private ProductSupplierRepository $productSupplierRepository,
        private ReplenishmentService $replenishmentService
    ) {
    }

    #[Route('/boutique/{boutiqueId}/product/{productId}/history-data', name: 'app_product_history_data')]
    public function getHistoryData(int $boutiqueId, int $productId, Request $request): Response
    {
        $user = $this->getUser();

        // Check access
        if (!$user->isSuperAdmin() && !$user->hasAccessToBoutique($boutiqueId)) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        $period = $request->query->get('period', '30');
        $startDate = $request->query->get('startDate');
        $endDate = $request->query->get('endDate');
        $showOrders = $request->query->get('showOrders', '0') === '1';

        // Determine date range
        $start = null;
        $end = null;
        $days = null;

        if ($startDate && $endDate && $period === 'custom') {
            try {
                $start = new \DateTimeImmutable($startDate);
                $end = new \DateTimeImmutable($endDate);
            } catch (\Exception $e) {
                $period = '30';
                $days = 30;
            }
        } else {
            $days = (int) $period;
        }

        // Get stock history
        $qb = $this->dailyStockRepository->createQueryBuilder('ds')
            ->where('ds.boutique = :boutiqueId')
            ->andWhere('ds.productId = :productId')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('productId', $productId);

        if ($start && $end) {
            $qb->andWhere('ds.collectedAt >= :start')
               ->andWhere('ds.collectedAt <= :end')
               ->setParameter('start', $start)
               ->setParameter('end', $end);
        } else {
            $qb->andWhere('ds.collectedAt >= :startDate')
               ->setParameter('startDate', (new \DateTime())->modify("-{$days} days"));
        }

        $stockHistory = $qb->orderBy('ds.collectedAt', 'ASC')
            ->getQuery()
            ->getResult();

        // Prepare history data
        $historyData = array_map(function($stock) {
            return [
                'productId' => $stock->getProductId(),
                'name' => $stock->getName(),
                'reference' => $stock->getReference(),
                'quantity' => $stock->getQuantity(),
                'collectedAt' => $stock->getCollectedAt()->format('Y-m-d H:i:s'),
            ];
        }, $stockHistory);

        // Calculate stats
        $stats = null;
        if (!empty($stockHistory)) {
            $firstStock = $stockHistory[0];
            $lastStock = end($stockHistory);
            $totalQuantity = array_sum(array_map(fn($s) => $s->getQuantity(), $stockHistory));
            $avgStock = round($totalQuantity / count($stockHistory));
            $evolution = $lastStock->getQuantity() - $firstStock->getQuantity();

            $stats = [
                'current' => $lastStock->getQuantity(),
                'average' => $avgStock,
                'evolution' => $evolution,
            ];
        }

        // Get order data if requested
        $orderData = [];
        if ($showOrders && !empty($stockHistory)) {
            $boutique = $stockHistory[0]->getBoutique();
            if ($boutique) {
                $rawOrderData = $this->dailyStockRepository->findProductOrdersByPeriod($boutique, $productId, $start, $end, $days);
                // Format for JavaScript (convert associative array to indexed array)
                foreach ($rawOrderData as $date => $data) {
                    $orderData[] = [
                        'order_date' => $date,
                        'total_quantity' => $data['quantity'],
                        'revenue' => $data['revenue']
                    ];
                }
            }
        }

        return $this->json([
            'history' => $historyData,
            'orders' => $orderData,
            'stats' => $stats,
        ]);
    }

    #[Route('/boutique/{boutiqueId}/product/{productId}', name: 'app_product_detail')]
    public function detail(int $boutiqueId, int $productId, Request $request): Response
    {
        $user = $this->getUser();

        // Check access
        if (!$user->isSuperAdmin() && !$user->hasAccessToBoutique($boutiqueId)) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à cette boutique');
        }

        // Get the boutique first
        $dailyStock = $this->dailyStockRepository->createQueryBuilder('ds')
            ->where('ds.boutique = :boutiqueId')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$dailyStock) {
            throw $this->createNotFoundException('Boutique non trouvée');
        }

        $boutique = $dailyStock->getBoutique();

        // Get current product info (latest stock entry)
        $product = $this->dailyStockRepository->createQueryBuilder('ds')
            ->where('ds.boutique = :boutiqueId')
            ->andWhere('ds.productId = :productId')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('productId', $productId)
            ->orderBy('ds.collectedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$product) {
            throw $this->createNotFoundException('Produit non trouvé');
        }

        // Get parameters for stock history chart
        $period = $request->query->get('period', '30'); // 7, 30, 90 days or 'custom'
        $startDate = $request->query->get('startDate');
        $endDate = $request->query->get('endDate');
        $showOrders = $request->query->get('showOrders', '0') === '1';

        // Determine date range for stock history
        $start = null;
        $end = null;
        $days = null;

        if ($startDate && $endDate && $period === 'custom') {
            // Custom date range
            try {
                $start = new \DateTimeImmutable($startDate);
                $end = new \DateTimeImmutable($endDate);
            } catch (\Exception $e) {
                // Invalid dates, fallback to 30 days
                $period = '30';
                $days = 30;
            }
        } else {
            // Predefined period
            $days = (int) $period;
        }

        // Get stock history based on period
        $qb = $this->dailyStockRepository->createQueryBuilder('ds')
            ->where('ds.boutique = :boutiqueId')
            ->andWhere('ds.productId = :productId')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('productId', $productId);

        if ($start && $end) {
            $qb->andWhere('ds.collectedAt >= :start')
               ->andWhere('ds.collectedAt <= :end')
               ->setParameter('start', $start)
               ->setParameter('end', $end);
        } else {
            $qb->andWhere('ds.collectedAt >= :startDate')
               ->setParameter('startDate', (new \DateTime())->modify("-{$days} days"));
        }

        $stockHistory = $qb->orderBy('ds.collectedAt', 'ASC')
            ->getQuery()
            ->getResult();

        // Get sales history
        $salesHistory = $this->orderRepository->createQueryBuilder('o')
            ->select('o.orderDate', 'SUM(oi.quantity) as quantity', 'SUM(oi.totalPrice) as revenue', 'COUNT(o.id) as orders')
            ->join('o.items', 'oi')
            ->where('o.boutique = :boutiqueId')
            ->andWhere('oi.productId = :productId')
            ->andWhere('o.orderDate >= :startDate')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('productId', $productId)
            ->setParameter('startDate', (new \DateTime())->modify('-90 days'))
            ->groupBy('o.orderDate')
            ->orderBy('o.orderDate', 'ASC')
            ->getQuery()
            ->getResult();

        // Get recent sales (last 50)
        $recentSales = $this->orderRepository->createQueryBuilder('o')
            ->select('o.orderDate', 'o.reference', 'oi.quantity', 'oi.unitPrice', 'oi.totalPrice', 'oi.wholesalePrice')
            ->join('o.items', 'oi')
            ->where('o.boutique = :boutiqueId')
            ->andWhere('oi.productId = :productId')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('productId', $productId)
            ->orderBy('o.orderDate', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        // Calculate sales stats
        $salesStats = $this->calculateSalesStats($recentSales);

        // Get supplier info
        $productSupplier = $this->productSupplierRepository->findByProduct($boutiqueId, $productId);

        // Calculate replenishment recommendation
        $replenishment = $this->replenishmentService->calculateReplenishment(
            $boutiqueId,
            $productId,
            $product->getQuantity(),
            $boutique->getLowStockThreshold()
        );

        // Prepare stock history data for chart
        $historyData = array_map(function($stock) {
            return [
                'productId' => $stock->getProductId(),
                'name' => $stock->getName(),
                'reference' => $stock->getReference(),
                'quantity' => $stock->getQuantity(),
                'collectedAt' => $stock->getCollectedAt()->format('Y-m-d H:i:s'),
            ];
        }, $stockHistory);

        // Get earliest and latest stock dates for date picker constraints
        $earliestStock = $this->dailyStockRepository->createQueryBuilder('ds')
            ->where('ds.boutique = :boutiqueId')
            ->andWhere('ds.productId = :productId')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('productId', $productId)
            ->orderBy('ds.collectedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $latestStock = $this->dailyStockRepository->createQueryBuilder('ds')
            ->where('ds.boutique = :boutiqueId')
            ->andWhere('ds.productId = :productId')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('productId', $productId)
            ->orderBy('ds.collectedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $minDate = $earliestStock ? $earliestStock->getCollectedAt()->format('Y-m-d') : null;
        $maxDate = $latestStock ? $latestStock->getCollectedAt()->format('Y-m-d') : (new \DateTime())->format('Y-m-d');

        // Get all dates where we have stock data
        $availableStocks = $this->dailyStockRepository->createQueryBuilder('ds')
            ->select('ds.collectedAt')
            ->where('ds.boutique = :boutiqueId')
            ->andWhere('ds.productId = :productId')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('productId', $productId)
            ->orderBy('ds.collectedAt', 'ASC')
            ->getQuery()
            ->getResult();

        // Extract unique dates (Y-m-d format) from collected timestamps
        $datesMap = [];
        foreach ($availableStocks as $stock) {
            $dateStr = $stock['collectedAt']->format('Y-m-d');
            $datesMap[$dateStr] = true;
        }
        $availableDatesArray = array_keys($datesMap);

        // Get order data if requested
        $orderData = [];
        if ($showOrders) {
            $orderData = $this->dailyStockRepository->findProductOrdersByPeriod($boutique, $productId, $start, $end, $days);
        }

        return $this->render('product/detail.html.twig', [
            'boutique' => $boutique,
            'product' => $product,
            'stockHistory' => $stockHistory,
            'historyData' => $historyData,
            'orderData' => $orderData,
            'salesHistory' => $salesHistory,
            'recentSales' => $recentSales,
            'salesStats' => $salesStats,
            'productSupplier' => $productSupplier,
            'supplier' => $productSupplier?->getSupplier(),
            'replenishment' => $replenishment,
            'period' => $period,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'showOrders' => $showOrders,
            'minDate' => $minDate,
            'maxDate' => $maxDate,
            'availableDates' => $availableDatesArray,
        ]);
    }

    private function calculateSalesStats(array $sales): array
    {
        if (empty($sales)) {
            return [
                'total_quantity' => 0,
                'total_revenue' => 0,
                'average_price' => 0,
                'total_margin' => 0,
                'margin_percent' => 0,
            ];
        }

        $totalQuantity = 0;
        $totalRevenue = 0;
        $totalCost = 0;

        foreach ($sales as $sale) {
            $quantity = $sale['quantity'] ?? 0;
            $totalPrice = floatval($sale['totalPrice'] ?? 0);
            $wholesalePrice = floatval($sale['wholesalePrice'] ?? 0);

            $totalQuantity += $quantity;
            $totalRevenue += $totalPrice;
            $totalCost += $wholesalePrice * $quantity;
        }

        $totalMargin = $totalRevenue - $totalCost;
        $marginPercent = $totalRevenue > 0 ? ($totalMargin / $totalRevenue) * 100 : 0;
        $averagePrice = $totalQuantity > 0 ? $totalRevenue / $totalQuantity : 0;

        return [
            'total_quantity' => $totalQuantity,
            'total_revenue' => round($totalRevenue, 2),
            'average_price' => round($averagePrice, 2),
            'total_margin' => round($totalMargin, 2),
            'margin_percent' => round($marginPercent, 2),
        ];
    }
}
