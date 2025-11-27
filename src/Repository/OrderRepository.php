<?php

namespace App\Repository;

use App\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * Get total revenue for a boutique in a date range
     */
    public function getTotalRevenue(int $boutiqueId, \DateTimeInterface $startDate, \DateTimeInterface $endDate): float
    {
        $result = $this->createQueryBuilder('o')
            ->select('SUM(o.totalPaid) as total')
            ->where('o.boutique = :boutiqueId')
            ->andWhere('o.orderDate >= :startDate')
            ->andWhere('o.orderDate <= :endDate')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Count orders for a boutique in a date range
     */
    public function countOrders(int $boutiqueId, \DateTimeInterface $startDate, \DateTimeInterface $endDate): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.boutique = :boutiqueId')
            ->andWhere('o.orderDate >= :startDate')
            ->andWhere('o.orderDate <= :endDate')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get recent orders for a boutique
     */
    public function getRecentOrders(int $boutiqueId, int $limit = 10): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.boutique = :boutiqueId')
            ->setParameter('boutiqueId', $boutiqueId)
            ->orderBy('o.orderDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find orders by boutique with filters
     */
    public function findByBoutiqueWithFilters(
        int $boutiqueId,
        string $search = '',
        string $status = '',
        string $startDate = '',
        string $endDate = '',
        int $limit = 50,
        int $offset = 0,
        string $sort = 'date',
        string $order = 'desc'
    ): array {
        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.items', 'items')
            ->addSelect('items')
            ->where('o.boutique = :boutiqueId')
            ->setParameter('boutiqueId', $boutiqueId);

        if (!empty($search)) {
            $qb->andWhere('o.reference LIKE :search OR o.orderId LIKE :search OR o.payment LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if (!empty($status)) {
            $qb->andWhere('o.currentState = :status')
                ->setParameter('status', $status);
        }

        if (!empty($startDate)) {
            try {
                $start = new \DateTimeImmutable($startDate);
                $qb->andWhere('o.orderDate >= :startDate')
                    ->setParameter('startDate', $start);
            } catch (\Exception $e) {
                // Invalid date, ignore
            }
        }

        if (!empty($endDate)) {
            try {
                $end = new \DateTimeImmutable($endDate);
                $end = $end->setTime(23, 59, 59);
                $qb->andWhere('o.orderDate <= :endDate')
                    ->setParameter('endDate', $end);
            } catch (\Exception $e) {
                // Invalid date, ignore
            }
        }

        // Build ORDER BY clause based on sort parameters
        $allowedSorts = [
            'date' => 'o.orderDate',
            'reference' => 'o.reference',
            'amount' => 'o.totalPaid',
            'margin' => 'o.totalPaid', // We'll use a workaround since margin is calculated
            'order_id' => 'o.orderId'
        ];

        $orderDirection = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $sortField = $allowedSorts[$sort] ?? 'o.orderDate';

        return $qb->orderBy($sortField, $orderDirection)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count orders by boutique with filters
     */
    public function countByBoutiqueWithFilters(
        int $boutiqueId,
        string $search = '',
        string $status = '',
        string $startDate = '',
        string $endDate = ''
    ): int {
        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.boutique = :boutiqueId')
            ->setParameter('boutiqueId', $boutiqueId);

        if (!empty($search)) {
            $qb->andWhere('o.reference LIKE :search OR o.orderId LIKE :search OR o.payment LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if (!empty($status)) {
            $qb->andWhere('o.currentState = :status')
                ->setParameter('status', $status);
        }

        if (!empty($startDate)) {
            try {
                $start = new \DateTimeImmutable($startDate);
                $qb->andWhere('o.orderDate >= :startDate')
                    ->setParameter('startDate', $start);
            } catch (\Exception $e) {
                // Invalid date, ignore
            }
        }

        if (!empty($endDate)) {
            try {
                $end = new \DateTimeImmutable($endDate);
                $end = $end->setTime(23, 59, 59);
                $qb->andWhere('o.orderDate <= :endDate')
                    ->setParameter('endDate', $end);
            } catch (\Exception $e) {
                // Invalid date, ignore
            }
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get all statistics for a boutique in one optimized query
     * Returns current period stats, previous period stats, and combines all data
     */
    public function getStatistics(
        int $boutiqueId,
        \DateTimeInterface $currentStart,
        \DateTimeInterface $currentEnd,
        \DateTimeInterface $previousStart,
        \DateTimeInterface $previousEnd
    ): array {
        // Use native SQL instead of DQL to avoid syntax issues with CASE/NULL
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                SUM(CASE WHEN order_date >= ? AND order_date <= ? THEN total_paid ELSE 0 END) as current_revenue,
                COUNT(CASE WHEN order_date >= ? AND order_date <= ? THEN 1 END) as current_order_count,
                SUM(CASE WHEN order_date >= ? AND order_date <= ? THEN total_paid ELSE 0 END) as previous_revenue,
                COUNT(CASE WHEN order_date >= ? AND order_date <= ? THEN 1 END) as previous_order_count
            FROM orders
            WHERE boutique_id = ?
                AND order_date >= ?
        ";

        $result = $conn->fetchAssociative($sql, [
            $currentStart->format('Y-m-d H:i:s'),
            $currentEnd->format('Y-m-d H:i:s'),
            $currentStart->format('Y-m-d H:i:s'),
            $currentEnd->format('Y-m-d H:i:s'),
            $previousStart->format('Y-m-d H:i:s'),
            $previousEnd->format('Y-m-d H:i:s'),
            $previousStart->format('Y-m-d H:i:s'),
            $previousEnd->format('Y-m-d H:i:s'),
            $boutiqueId,
            $previousStart->format('Y-m-d H:i:s'),
        ]);

        return [
            'currentRevenue' => (float) ($result['current_revenue'] ?? 0),
            'currentOrderCount' => (int) ($result['current_order_count'] ?? 0),
            'previousRevenue' => (float) ($result['previous_revenue'] ?? 0),
            'previousOrderCount' => (int) ($result['previous_order_count'] ?? 0),
        ];
    }

    /**
     * Get total revenue with filters applied (for orders page)
     */
    public function getTotalRevenueWithFilters(
        int $boutiqueId,
        string $search = '',
        string $status = '',
        string $startDate = '',
        string $endDate = ''
    ): float {
        $qb = $this->createQueryBuilder('o')
            ->select('SUM(o.totalPaid)')
            ->where('o.boutique = :boutiqueId')
            ->setParameter('boutiqueId', $boutiqueId);

        if (!empty($search)) {
            $qb->andWhere('o.reference LIKE :search OR o.orderId LIKE :search OR o.payment LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if (!empty($status)) {
            $qb->andWhere('o.currentState = :status')
                ->setParameter('status', $status);
        }

        if (!empty($startDate)) {
            try {
                $start = new \DateTimeImmutable($startDate);
                $qb->andWhere('o.orderDate >= :startDate')
                    ->setParameter('startDate', $start);
            } catch (\Exception $e) {
                // Invalid date, ignore
            }
        }

        if (!empty($endDate)) {
            try {
                $end = new \DateTimeImmutable($endDate);
                $end = $end->setTime(23, 59, 59);
                $qb->andWhere('o.orderDate <= :endDate')
                    ->setParameter('endDate', $end);
            } catch (\Exception $e) {
                // Invalid date, ignore
            }
        }

        return (float) ($qb->getQuery()->getSingleScalarResult() ?? 0);
    }

    /**
     * Get top selling products for analytics
     * Returns products ordered by quantity sold in descending order
     */
    public function getTopSellingProducts(
        int $boutiqueId,
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        int $limit = 20,
        string $sort = 'quantity',
        string $order = 'desc'
    ): array {
        $conn = $this->getEntityManager()->getConnection();

        // Map sort field names to SQL column names
        $sortFieldMap = [
            'quantity' => 'total_quantity',
            'orderCount' => 'order_count',
            'revenue' => 'total_revenue',
            'avgPrice' => 'avg_price'
        ];

        // Validate and get sort field
        $sortField = $sortFieldMap[$sort] ?? 'total_quantity';

        // Validate order direction
        $orderDirection = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "
            SELECT
                oi.product_id,
                oi.product_name,
                oi.product_reference,
                SUM(oi.quantity) as total_quantity,
                COUNT(DISTINCT o.id) as order_count,
                SUM(oi.quantity * oi.unit_price) as total_revenue,
                AVG(oi.unit_price) as avg_price
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE o.boutique_id = ?
                AND o.order_date >= ?
                AND o.order_date <= ?
            GROUP BY oi.product_id, oi.product_name, oi.product_reference
            ORDER BY {$sortField} {$orderDirection}
            LIMIT ?
        ";

        $results = $conn->fetchAllAssociative($sql, [
            $boutiqueId,
            $startDate->format('Y-m-d H:i:s'),
            $endDate->format('Y-m-d H:i:s'),
            $limit
        ]);

        return array_map(function($row) {
            return [
                'productId' => (int) $row['product_id'],
                'productName' => $row['product_name'],
                'productReference' => $row['product_reference'],
                'totalQuantity' => (int) $row['total_quantity'],
                'orderCount' => (int) $row['order_count'],
                'totalRevenue' => (float) $row['total_revenue'],
                'avgPrice' => (float) $row['avg_price'],
            ];
        }, $results);
    }

    /**
     * Get sales data by product and day for stocks page
     * Returns array indexed by [product_id][date] with quantity sold and revenue
     */
    public function getSalesByProductAndDay(
        int $boutiqueId,
        array $productIds,
        array $dates
    ): array {
        if (empty($productIds) || empty($dates)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        // Convert dates array to proper format for query
        $dateConditions = [];
        foreach ($dates as $date) {
            $dateConditions[] = $conn->quote($date);
        }
        $dateInClause = implode(',', $dateConditions);

        $productIdsInClause = implode(',', array_map('intval', $productIds));

        $sql = "
            SELECT
                oi.product_id,
                DATE(o.order_date) as order_date,
                SUM(oi.quantity) as total_quantity,
                SUM(oi.total_price) as total_revenue
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE o.boutique_id = :boutique_id
                AND oi.product_id IN ({$productIdsInClause})
                AND DATE(o.order_date) IN ({$dateInClause})
            GROUP BY oi.product_id, DATE(o.order_date)
        ";

        $results = $conn->fetchAllAssociative($sql, [
            'boutique_id' => $boutiqueId
        ]);

        // Index results by product_id and date
        $indexed = [];
        foreach ($results as $row) {
            $productId = $row['product_id'];
            $date = $row['order_date'];
            $indexed[$productId][$date] = [
                'quantity' => (int) $row['total_quantity'],
                'revenue' => (float) $row['total_revenue']
            ];
        }

        return $indexed;
    }
}
