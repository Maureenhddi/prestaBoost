<?php

namespace App\Service;

use App\Repository\DailyStockRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductSupplierRepository;

class ReplenishmentService
{
    public function __construct(
        private DailyStockRepository $dailyStockRepository,
        private OrderRepository $orderRepository,
        private ProductSupplierRepository $productSupplierRepository
    ) {
    }

    /**
     * Calculate replenishment suggestions for a product
     */
    public function calculateReplenishment(
        int $boutiqueId,
        int $productId,
        int $currentStock,
        int $safetyStock = 10,
        int $targetDays = 15
    ): array {
        // Calculate sales velocity (units per day)
        $salesVelocity = $this->calculateSalesVelocity($boutiqueId, $productId);

        // Days of stock remaining
        $daysRemaining = $salesVelocity > 0 ? round($currentStock / $salesVelocity, 1) : 999;

        // Calculate needed quantity
        $targetStock = ceil($salesVelocity * $targetDays);
        $neededQuantity = max(0, $targetStock + $safetyStock - $currentStock);

        // Get supplier info
        $productSupplier = $this->productSupplierRepository->findByProduct($boutiqueId, $productId);

        // Adjust for minimum order quantity
        $minimumQty = $productSupplier?->getMinimumOrderQuantity() ?? 10;
        if ($neededQuantity > 0 && $neededQuantity < $minimumQty) {
            $neededQuantity = $minimumQty;
        }

        // Round to minimum quantity
        if ($neededQuantity > 0 && $minimumQty > 1) {
            $neededQuantity = ceil($neededQuantity / $minimumQty) * $minimumQty;
        }

        // Calculate costs
        $wholesalePrice = $productSupplier?->getWholesalePrice() ?? 0;
        $totalCost = $neededQuantity * floatval($wholesalePrice);

        // Apply discount if applicable
        $discountPercent = 0;
        $discountThreshold = $productSupplier?->getDiscountThreshold();
        if ($discountThreshold && $neededQuantity >= $discountThreshold) {
            $discountPercent = floatval($productSupplier->getDiscountPercent() ?? 0);
            $totalCost = $totalCost * (1 - $discountPercent / 100);
        }

        // Calculate delivery date
        $leadTimeDays = $productSupplier?->getSupplier()?->getLeadTimeDays() ?? 7;
        $deliveryDate = (new \DateTime())->modify("+{$leadTimeDays} days");

        // Determine urgency level
        $urgency = $this->determineUrgency($daysRemaining, $salesVelocity);

        return [
            'current_stock' => $currentStock,
            'safety_stock' => $safetyStock,
            'sales_velocity' => round($salesVelocity, 2),
            'days_remaining' => $daysRemaining,
            'target_days' => $targetDays,
            'target_stock' => $targetStock,
            'needed_quantity' => $neededQuantity,
            'wholesale_price' => $wholesalePrice,
            'total_cost_ht' => round($totalCost, 2),
            'total_cost_ttc' => round($totalCost * 1.20, 2),
            'discount_percent' => $discountPercent,
            'minimum_order_qty' => $minimumQty,
            'lead_time_days' => $leadTimeDays,
            'estimated_delivery_date' => $deliveryDate,
            'urgency' => $urgency,
            'supplier' => $productSupplier?->getSupplier(),
            'product_supplier' => $productSupplier,
            'should_order' => $neededQuantity > 0,
            'is_critical' => $urgency === 'critical',
            'is_urgent' => $urgency === 'urgent',
        ];
    }

    /**
     * Calculate sales velocity (units per day) over last 30 days
     */
    private function calculateSalesVelocity(int $boutiqueId, int $productId, int $days = 30): float
    {
        $sales = $this->orderRepository->createQueryBuilder('o')
            ->select('SUM(oi.quantity) as total_quantity')
            ->join('o.items', 'oi')
            ->where('o.boutique = :boutiqueId')
            ->andWhere('oi.productId = :productId')
            ->andWhere('o.orderDate >= :startDate')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('productId', $productId)
            ->setParameter('startDate', (new \DateTime())->modify("-{$days} days"))
            ->getQuery()
            ->getSingleScalarResult();

        return $sales ? floatval($sales) / $days : 0;
    }

    /**
     * Determine urgency level based on days remaining
     */
    private function determineUrgency(float $daysRemaining, float $salesVelocity): string
    {
        if ($salesVelocity === 0) {
            return 'none'; // No sales = no urgency
        }

        if ($daysRemaining <= 3) {
            return 'critical'; // 🔴
        }

        if ($daysRemaining <= 7) {
            return 'urgent'; // 🟠
        }

        if ($daysRemaining <= 14) {
            return 'warning'; // 🟡
        }

        return 'normal'; // 🟢
    }

    /**
     * Get all products needing replenishment for a boutique
     */
    public function getReplenishmentList(int $boutiqueId, int $lowStockThreshold = 10): array
    {
        // Get all products with their current stock
        $products = $this->dailyStockRepository->findLowStockProducts($boutiqueId, $lowStockThreshold, 100);

        $replenishmentList = [];

        foreach ($products as $product) {
            $calculation = $this->calculateReplenishment(
                $boutiqueId,
                $product['product_id'],
                $product['quantity'],
                $lowStockThreshold
            );

            if ($calculation['should_order']) {
                $replenishmentList[] = array_merge($product, $calculation);
            }
        }

        // Sort by urgency (critical first)
        usort($replenishmentList, function ($a, $b) {
            $urgencyOrder = ['critical' => 0, 'urgent' => 1, 'warning' => 2, 'normal' => 3];
            $aOrder = $urgencyOrder[$a['urgency']] ?? 999;
            $bOrder = $urgencyOrder[$b['urgency']] ?? 999;
            return $aOrder <=> $bOrder;
        });

        return $replenishmentList;
    }

    /**
     * Get summary statistics for replenishment
     */
    public function getReplenishmentSummary(int $boutiqueId): array
    {
        $list = $this->getReplenishmentList($boutiqueId);

        $critical = 0;
        $urgent = 0;
        $warning = 0;
        $totalCost = 0;

        foreach ($list as $item) {
            switch ($item['urgency']) {
                case 'critical':
                    $critical++;
                    break;
                case 'urgent':
                    $urgent++;
                    break;
                case 'warning':
                    $warning++;
                    break;
            }
            $totalCost += $item['total_cost_ht'];
        }

        return [
            'total_products' => count($list),
            'critical' => $critical,
            'urgent' => $urgent,
            'warning' => $warning,
            'total_cost_ht' => round($totalCost, 2),
            'total_cost_ttc' => round($totalCost * 1.20, 2),
        ];
    }
}
