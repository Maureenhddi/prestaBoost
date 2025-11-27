<?php

namespace App\Repository;

use App\Entity\Boutique;
use App\Entity\DailyStock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DailyStock>
 */
class DailyStockRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private OrderRepository $orderRepository
    ) {
        parent::__construct($registry, DailyStock::class);
    }

    /**
     * Get latest stock snapshot for all boutiques or a specific boutique
     */
    public function findLatestSnapshot(?Boutique $boutique = null): array
    {
        $qb = $this->createQueryBuilder('ds')
            ->select('ds, b')
            ->join('ds.boutique', 'b')
            ->where('ds.collectedAt = (
                SELECT MAX(ds2.collectedAt)
                FROM App\Entity\DailyStock ds2
                WHERE ds2.boutique = ds.boutique
            )');

        if ($boutique) {
            $qb->andWhere('ds.boutique = :boutique')
                ->setParameter('boutique', $boutique);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get latest stock snapshot with pagination
     */
    public function findLatestSnapshotPaginated(?Boutique $boutique = null, int $page = 1, int $perPage = 50): Paginator
    {
        $qb = $this->createQueryBuilder('ds')
            ->select('ds, b')
            ->join('ds.boutique', 'b')
            ->where('ds.collectedAt = (
                SELECT MAX(ds2.collectedAt)
                FROM App\Entity\DailyStock ds2
                WHERE ds2.boutique = ds.boutique
            )')
            ->orderBy('ds.name', 'ASC');

        if ($boutique) {
            $qb->andWhere('ds.boutique = :boutique')
                ->setParameter('boutique', $boutique);
        }

        $query = $qb->getQuery()
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return new Paginator($query);
    }

    /**
     * Get stock history for a specific product in a boutique
     */
    public function findProductHistory(Boutique $boutique, int $productId, int $days = 30): array
    {
        $date = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('ds')
            ->where('ds.boutique = :boutique')
            ->andWhere('ds.productId = :productId')
            ->andWhere('ds.collectedAt >= :date')
            ->setParameter('boutique', $boutique)
            ->setParameter('productId', $productId)
            ->setParameter('date', $date)
            ->orderBy('ds.collectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get product history between two specific dates
     */
    public function findProductHistoryByDateRange(
        Boutique $boutique,
        int $productId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate
    ): array {
        return $this->createQueryBuilder('ds')
            ->where('ds.boutique = :boutique')
            ->andWhere('ds.productId = :productId')
            ->andWhere('ds.collectedAt >= :startDate')
            ->andWhere('ds.collectedAt <= :endDate')
            ->setParameter('boutique', $boutique)
            ->setParameter('productId', $productId)
            ->setParameter('startDate', $startDate->setTime(0, 0, 0))
            ->setParameter('endDate', $endDate->setTime(23, 59, 59))
            ->orderBy('ds.collectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get stock snapshot for a specific date/time
     */
    public function findSnapshotByDate(?Boutique $boutique, \DateTimeImmutable $date): array
    {
        $qb = $this->createQueryBuilder('ds')
            ->select('ds')
            ->where('ds.collectedAt <= :date')
            ->andWhere('ds.collectedAt = (
                SELECT MAX(ds2.collectedAt)
                FROM App\Entity\DailyStock ds2
                WHERE ds2.boutique = ds.boutique
                AND ds2.productId = ds.productId
                AND ds2.collectedAt <= :date
            )')
            ->setParameter('date', $date);

        if ($boutique) {
            $qb->andWhere('ds.boutique = :boutique')
                ->setParameter('boutique', $boutique);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get the latest collection date for a boutique
     */
    public function getLatestCollectionDate(Boutique $boutique): ?\DateTimeImmutable
    {
        $result = $this->createQueryBuilder('ds')
            ->select('ds.collectedAt')
            ->where('ds.boutique = :boutique')
            ->setParameter('boutique', $boutique)
            ->orderBy('ds.collectedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result ? $result['collectedAt'] : null;
    }

    /**
     * Get statistics for latest snapshot (optimized - uses SQL aggregation)
     */
    public function getLatestSnapshotStats(Boutique $boutique, ?\DateTimeImmutable $latestDate = null): array
    {
        // Get the latest collection date if not provided
        if (!$latestDate) {
            $latestDate = $this->getLatestCollectionDate($boutique);
        }

        if (!$latestDate) {
            return [
                'totalProducts' => 0,
                'outOfStock' => 0,
                'lowStock' => 0,
            ];
        }

        // Calculate stats with a single optimized query
        $result = $this->createQueryBuilder('ds')
            ->select('
                COUNT(ds.id) as totalProducts,
                SUM(CASE WHEN ds.quantity = 0 THEN 1 ELSE 0 END) as outOfStock,
                SUM(CASE WHEN ds.quantity > 0 AND ds.quantity < 10 THEN 1 ELSE 0 END) as lowStock
            ')
            ->where('ds.boutique = :boutique')
            ->andWhere('ds.collectedAt = :latestDate')
            ->setParameter('boutique', $boutique)
            ->setParameter('latestDate', $latestDate)
            ->getQuery()
            ->getSingleResult();

        return [
            'totalProducts' => (int) ($result['totalProducts'] ?? 0),
            'outOfStock' => (int) ($result['outOfStock'] ?? 0),
            'lowStock' => (int) ($result['lowStock'] ?? 0),
        ];
    }

    /**
     * Find stocks with pagination, filtering, search and variations - all in SQL
     */
    public function findStocksWithVariations(
        Boutique $boutique,
        ?\DateTimeImmutable $compareDate,
        string $filter = 'all',
        string $search = '',
        int $page = 1,
        int $perPage = 50
    ): array {
        $conn = $this->getEntityManager()->getConnection();

        // Get the latest collection date first
        $latestDate = $conn->fetchOne(
            'SELECT MAX(collected_at) FROM daily_stocks WHERE boutique_id = ?',
            [$boutique->getId()]
        );

        if (!$latestDate) {
            return ['stocks' => [], 'total' => 0];
        }

        // Get the reference date for comparison (eliminate correlated subquery!)
        $referenceDate = null;
        if ($compareDate) {
            $referenceDate = $conn->fetchOne(
                'SELECT MAX(collected_at) FROM daily_stocks WHERE boutique_id = ? AND collected_at <= ?',
                [$boutique->getId(), $compareDate->format('Y-m-d H:i:s')]
            );
        }

        // Build base parameters
        $params = [
            'boutique_id' => $boutique->getId(),
            'latest_date' => $latestDate,
        ];

        // Build WHERE clause for filtering
        $filterCondition = match($filter) {
            'outofstock' => 'AND current.quantity = 0',
            'low' => 'AND current.quantity > 0 AND current.quantity < 10',
            'changes' => 'AND (prev.quantity IS NOT NULL AND current.quantity != prev.quantity)',
            default => '', // 'all'
        };

        // Build WHERE clause for search
        $searchCondition = '';
        if ($search !== '') {
            $searchCondition = 'AND (LOWER(current.name) LIKE :search OR LOWER(current.reference) LIKE :search)';
            $params['search'] = '%' . mb_strtolower($search) . '%';
        }

        // Build JOIN for comparison (NO MORE CORRELATED SUBQUERY!)
        $compareDateSql = '';
        if ($referenceDate) {
            $params['reference_date'] = $referenceDate;
            $compareDateSql = "LEFT JOIN daily_stocks prev
                ON current.product_id = prev.product_id
                AND current.boutique_id = prev.boutique_id
                AND prev.collected_at = :reference_date";
        }

        // First, count total matching records
        $countSql = "
            SELECT COUNT(*)
            FROM daily_stocks current
            $compareDateSql
            WHERE current.boutique_id = :boutique_id
                AND current.collected_at = :latest_date
                $filterCondition
                $searchCondition
        ";

        $total = (int) $conn->fetchOne($countSql, $params);

        // Get paginated results with variations
        $offset = ($page - 1) * $perPage;
        $params['limit'] = $perPage;
        $params['offset'] = $offset;

        $sql = "
            SELECT
                current.id,
                current.product_id,
                current.name,
                current.reference,
                current.quantity as current_quantity,
                " . ($referenceDate ? "prev.quantity as previous_quantity,
                CASE
                    WHEN prev.quantity IS NOT NULL THEN (current.quantity - prev.quantity)
                    ELSE NULL
                END as variation,
                CASE
                    WHEN prev.quantity IS NOT NULL AND prev.quantity > 0
                    THEN ROUND(((current.quantity - prev.quantity)::numeric / prev.quantity::numeric) * 100, 1)
                    ELSE NULL
                END as variation_percent" : "NULL as previous_quantity, NULL as variation, NULL as variation_percent") . "
            FROM daily_stocks current
            $compareDateSql
            WHERE current.boutique_id = :boutique_id
                AND current.collected_at = :latest_date
                $filterCondition
                $searchCondition
            ORDER BY current.name ASC
            LIMIT :limit OFFSET :offset
        ";

        $stocks = $conn->fetchAllAssociative($sql, $params);

        return [
            'stocks' => $stocks,
            'total' => $total,
        ];
    }

    /**
     * Get stock statistics for the stocks page (optimized)
     */
    public function getStocksPageStats(Boutique $boutique, ?\DateTimeImmutable $compareDate): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // Get the latest collection date
        $latestDate = $conn->fetchOne(
            'SELECT MAX(collected_at) FROM daily_stocks WHERE boutique_id = ?',
            [$boutique->getId()]
        );

        if (!$latestDate) {
            return ['total' => 0, 'outOfStock' => 0, 'lowStock' => 0, 'withChanges' => 0];
        }

        if ($compareDate) {
            // Get the reference date (eliminate correlated subquery!)
            $referenceDate = $conn->fetchOne(
                'SELECT MAX(collected_at) FROM daily_stocks WHERE boutique_id = ? AND collected_at <= ?',
                [$boutique->getId(), $compareDate->format('Y-m-d H:i:s')]
            );

            if ($referenceDate) {
                $sql = "
                    SELECT
                        COUNT(*) as total,
                        SUM(CASE WHEN current.quantity = 0 THEN 1 ELSE 0 END) as out_of_stock,
                        SUM(CASE WHEN current.quantity > 0 AND current.quantity < 10 THEN 1 ELSE 0 END) as low_stock,
                        SUM(CASE WHEN prev.quantity IS NOT NULL AND current.quantity != prev.quantity THEN 1 ELSE 0 END) as with_changes
                    FROM daily_stocks current
                    LEFT JOIN daily_stocks prev
                        ON current.product_id = prev.product_id
                        AND current.boutique_id = prev.boutique_id
                        AND prev.collected_at = ?
                    WHERE current.boutique_id = ?
                        AND current.collected_at = ?
                ";

                $result = $conn->fetchAssociative($sql, [
                    $referenceDate,
                    $boutique->getId(),
                    $latestDate
                ]);
            } else {
                // No reference date found, return stats without changes
                $sql = "
                    SELECT
                        COUNT(*) as total,
                        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock,
                        SUM(CASE WHEN quantity > 0 AND quantity < 10 THEN 1 ELSE 0 END) as low_stock,
                        0 as with_changes
                    FROM daily_stocks
                    WHERE boutique_id = ?
                        AND collected_at = ?
                ";

                $result = $conn->fetchAssociative($sql, [$boutique->getId(), $latestDate]);
            }
        } else {
            $sql = "
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock,
                    SUM(CASE WHEN quantity > 0 AND quantity < 10 THEN 1 ELSE 0 END) as low_stock,
                    0 as with_changes
                FROM daily_stocks
                WHERE boutique_id = ?
                    AND collected_at = ?
            ";

            $result = $conn->fetchAssociative($sql, [$boutique->getId(), $latestDate]);
        }

        return [
            'total' => (int) ($result['total'] ?? 0),
            'outOfStock' => (int) ($result['out_of_stock'] ?? 0),
            'lowStock' => (int) ($result['low_stock'] ?? 0),
            'withChanges' => (int) ($result['with_changes'] ?? 0),
        ];
    }

    /**
     * Get low stock products (optimized with LIMIT)
     */
    public function findLowStockProducts(Boutique $boutique, int $limit = 10, ?\DateTimeImmutable $latestDate = null): array
    {
        // Get the latest collection date if not provided
        if (!$latestDate) {
            $latestDate = $this->getLatestCollectionDate($boutique);
        }

        if (!$latestDate) {
            return [];
        }

        $lowStockThreshold = $boutique->getLowStockThreshold();

        return $this->createQueryBuilder('ds')
            ->where('ds.boutique = :boutique')
            ->andWhere('ds.collectedAt = :latestDate')
            ->andWhere('ds.quantity > 0')
            ->andWhere('ds.quantity < :threshold')
            ->setParameter('boutique', $boutique)
            ->setParameter('latestDate', $latestDate)
            ->setParameter('threshold', $lowStockThreshold)
            ->orderBy('ds.quantity', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get top selling products (optimized with CTE to avoid correlated subqueries)
     */
    public function findTopSellingProducts(Boutique $boutique, int $days = 7, int $limit = 10): array
    {
        $weekAgo = new \DateTimeImmutable("-{$days} days");
        $conn = $this->getEntityManager()->getConnection();

        // First, get the latest date and the reference date for comparison
        $dates = $conn->fetchAssociative('
            SELECT
                MAX(collected_at) as latest_date,
                (SELECT MAX(collected_at) FROM daily_stocks
                 WHERE boutique_id = :boutique_id AND collected_at <= :week_ago) as reference_date
            FROM daily_stocks
            WHERE boutique_id = :boutique_id
        ', [
            'boutique_id' => $boutique->getId(),
            'week_ago' => $weekAgo->format('Y-m-d H:i:s')
        ]);

        if (!$dates || !$dates['latest_date'] || !$dates['reference_date']) {
            return [];
        }

        // Now do a simple JOIN without correlated subqueries
        $sql = '
            SELECT
                current.product_id,
                current.name,
                current.reference,
                current.quantity as current_quantity,
                previous.quantity as previous_quantity,
                (previous.quantity - current.quantity) as sold
            FROM daily_stocks current
            INNER JOIN daily_stocks previous
                ON current.product_id = previous.product_id
                AND current.boutique_id = previous.boutique_id
                AND previous.collected_at = :reference_date
            WHERE current.boutique_id = :boutique_id
                AND current.collected_at = :latest_date
                AND previous.quantity > current.quantity
            ORDER BY sold DESC
            LIMIT :limit
        ';

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'boutique_id' => $boutique->getId(),
            'latest_date' => $dates['latest_date'],
            'reference_date' => $dates['reference_date'],
            'limit' => $limit
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Get stock statistics with filtering and search
     * Returns stats based on active filters (stock level filters + search)
     */
    public function getFilteredStockStats(
        Boutique $boutique,
        array $filters = [],
        ?string $search = null,
        ?int $lowStockThreshold = null
    ): array {
        // Use boutique's low stock threshold if not provided
        $lowStockThreshold = $lowStockThreshold ?? $boutique->getLowStockThreshold();
        $conn = $this->getEntityManager()->getConnection();

        // Get the latest collection date
        $latestDate = $conn->fetchOne('
            SELECT MAX(collected_at)
            FROM daily_stocks
            WHERE boutique_id = :boutique_id
        ', ['boutique_id' => $boutique->getId()]);

        if (!$latestDate) {
            return ['total' => 0, 'outOfStock' => 0, 'lowStock' => 0, 'inStock' => 0];
        }

        // Build WHERE clause for search
        $searchClause = '';
        $params = [
            'boutique_id' => $boutique->getId(),
            'latest_date' => $latestDate
        ];

        if ($search) {
            $searchClause = " AND (name ILIKE :search OR reference ILIKE :search)";
            $params['search'] = '%' . $search . '%';
        }

        // Build WHERE clause for stock level filters
        $filterClause = '';
        if (!empty($filters)) {
            $stockConditions = [];
            foreach ($filters as $filter) {
                if ($filter === 'outofstock') {
                    $stockConditions[] = 'quantity = 0';
                } elseif ($filter === 'low') {
                    $stockConditions[] = "(quantity > 0 AND quantity < {$lowStockThreshold})";
                } elseif ($filter === 'instock') {
                    $stockConditions[] = "quantity > 0";
                }
            }
            if (!empty($stockConditions)) {
                $filterClause = ' AND (' . implode(' OR ', $stockConditions) . ')';
            }
        }

        // Get statistics based on active filters
        $statsSql = "
            SELECT
                COUNT(DISTINCT product_id) as total,
                SUM(CASE WHEN quantity >= {$lowStockThreshold} THEN 1 ELSE 0 END) as in_stock,
                SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock,
                SUM(CASE WHEN quantity > 0 AND quantity < {$lowStockThreshold} THEN 1 ELSE 0 END) as low_stock
            FROM daily_stocks
            WHERE boutique_id = :boutique_id
                AND collected_at = :latest_date
                {$searchClause}
                {$filterClause}
        ";

        $statsResult = $conn->fetchAssociative($statsSql, $params);

        return [
            'total' => (int) ($statsResult['total'] ?? 0),
            'inStock' => (int) ($statsResult['in_stock'] ?? 0),
            'outOfStock' => (int) ($statsResult['out_of_stock'] ?? 0),
            'lowStock' => (int) ($statsResult['low_stock'] ?? 0),
        ];
    }

    /**
     * Get stocks with last N days history for each product
     * Returns product info with stock quantities for each of the last N days
     *
     * Optimized version using 2-step approach to avoid slow JOINs on large tables
     */
    public function findStocksWithLast7Days(
        Boutique $boutique,
        int $page = 1,
        int $perPage = 50,
        array $filters = [],
        ?string $search = null,
        int $days = 7,
        ?string $category = null,
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate = null,
        bool $showSales = false,
        bool $showRevenue = false,
        string $sort = 'name',
        string $order = 'asc',
        ?int $excludeOutOfStockDays = null,
        ?int $lowStockThreshold = null
    ): array {
        // Use boutique's low stock threshold if not provided
        $lowStockThreshold = $lowStockThreshold ?? $boutique->getLowStockThreshold();
        $conn = $this->getEntityManager()->getConnection();

        // Determine which date to use for filtering
        $useCustomDateRange = $startDate && $endDate;

        // Format dates once if custom range is used
        $startDateFormatted = null;
        $endDateFormatted = null;
        if ($useCustomDateRange) {
            $startDateFormatted = $startDate->setTime(0, 0, 0)->format('Y-m-d H:i:s');
            $endDateFormatted = $endDate->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        }

        if ($useCustomDateRange) {
            // Get the latest collection date within the custom date range
            $latestDate = $conn->fetchOne('
                SELECT MAX(collected_at)
                FROM daily_stocks
                WHERE boutique_id = :boutique_id
                    AND collected_at >= :start_date
                    AND collected_at <= :end_date
            ', [
                'boutique_id' => $boutique->getId(),
                'start_date' => $startDateFormatted,
                'end_date' => $endDateFormatted
            ]);

            if (!$latestDate) {
                return ['stocks' => [], 'dates' => [], 'latest_date' => null, 'total_count' => 0];
            }

            $latestDateTime = new \DateTimeImmutable($latestDate);
        } else {
            // Get the latest collection date
            $latestDate = $conn->fetchOne('
                SELECT MAX(collected_at)
                FROM daily_stocks
                WHERE boutique_id = :boutique_id
            ', ['boutique_id' => $boutique->getId()]);

            if (!$latestDate) {
                return ['stocks' => [], 'dates' => [], 'latest_date' => null, 'total_count' => 0];
            }

            $latestDateTime = new \DateTimeImmutable($latestDate);
        }

        $offset = ($page - 1) * $perPage;

        // Build WHERE clauses for filters
        $filterClause = '';
        $params = [
            'boutique_id' => $boutique->getId(),
            'limit' => $perPage,
            'offset' => $offset
        ];

        // Add date parameters - latest_date is always needed for the main query
        $params['latest_date'] = $latestDate;

        // Add custom date range parameters if needed (for history queries)
        if ($useCustomDateRange) {
            $params['start_date'] = $startDateFormatted;
            $params['end_date'] = $endDateFormatted;
        }

        if ($search) {
            $filterClause .= " AND (name ILIKE :search OR reference ILIKE :search)";
            $params['search'] = '%' . $search . '%';
        }

        // Apply category filter
        if ($category) {
            $filterClause .= " AND category = :category";
            $params['category'] = $category;
        }

        // Apply stock level filters (multiple selection)
        if (!empty($filters)) {
            $stockConditions = [];
            foreach ($filters as $filter) {
                if ($filter === 'outofstock') {
                    $stockConditions[] = 'quantity = 0';
                } elseif ($filter === 'low') {
                    $stockConditions[] = "(quantity > 0 AND quantity < {$lowStockThreshold})";
                } elseif ($filter === 'instock') {
                    // "In stock" means any quantity > 0 (includes both low stock and sufficient stock)
                    $stockConditions[] = "quantity > 0";
                }
            }
            if (!empty($stockConditions)) {
                $filterClause .= ' AND (' . implode(' OR ', $stockConditions) . ')';
            }
        }

        // First, get total count for pagination
        $countSql = "
            SELECT COUNT(DISTINCT product_id)
            FROM daily_stocks
            WHERE boutique_id = :boutique_id
                AND collected_at = :latest_date
                {$filterClause}
        ";
        $countParams = [
            'boutique_id' => $boutique->getId(),
            'latest_date' => $latestDate
        ];

        if ($search) {
            $countParams['search'] = $params['search'];
        }
        if ($category) {
            $countParams['category'] = $params['category'];
        }
        $totalCount = $conn->fetchOne($countSql, $countParams);

        // Build ORDER BY clause based on sort parameters
        $orderByClause = 'name ASC'; // default
        $allowedSorts = ['name', 'reference', 'category', 'out_of_stock'];
        $orderDirection = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        if (in_array($sort, $allowedSorts)) {
            if ($sort === 'out_of_stock') {
                // For out_of_stock, we need to sort by quantity = 0 count, but since we don't have it yet,
                // we'll just sort by current quantity (0 first if DESC, high first if ASC)
                $orderByClause = "quantity {$orderDirection}, name ASC";
            } else {
                $orderByClause = "{$sort} {$orderDirection}";
            }
        }

        // STEP 1: Get filtered products from the latest snapshot (whether custom period or default)
        $sql = "
            SELECT
                product_id,
                name,
                reference,
                category,
                quantity as current_quantity
            FROM daily_stocks
            WHERE boutique_id = :boutique_id
                AND collected_at = :latest_date
                {$filterClause}
            ORDER BY {$orderByClause}
            LIMIT :limit OFFSET :offset
        ";

        // Build params for this specific query (only parameters actually used in the SQL)
        $queryParams = [
            'boutique_id' => $boutique->getId(),
            'latest_date' => $latestDate,
            'limit' => $perPage,
            'offset' => $offset
        ];
        if ($search) {
            $queryParams['search'] = $params['search'];
        }
        if ($category) {
            $queryParams['category'] = $params['category'];
        }

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery($queryParams);
        $products = $result->fetchAllAssociative();

        if (empty($products)) {
            return ['stocks' => [], 'dates' => [], 'latest_date' => $latestDateTime, 'total_count' => 0];
        }

        // Get product IDs
        $productIds = array_column($products, 'product_id');

        // Get collection dates based on mode (custom range or last N days)
        if ($useCustomDateRange) {
            // Get all distinct dates in the custom range
            $last7Dates = $conn->fetchFirstColumn('
                SELECT DISTINCT DATE(collected_at) as date
                FROM daily_stocks
                WHERE boutique_id = :boutique_id
                    AND collected_at >= :start_date
                    AND collected_at <= :end_date
                ORDER BY date DESC
            ', [
                'boutique_id' => $boutique->getId(),
                'start_date' => $startDateFormatted,
                'end_date' => $endDateFormatted
            ]);
        } else {
            // Ensure days is between 1 and 90 (limit for performance)
            $days = max(1, min(90, $days));

            // Get last N collection dates
            $last7Dates = $conn->fetchFirstColumn('
                SELECT DISTINCT DATE(collected_at) as date
                FROM daily_stocks
                WHERE boutique_id = :boutique_id
                AND collected_at <= :latest_date
                ORDER BY date DESC
                LIMIT :days
            ', [
                'boutique_id' => $boutique->getId(),
                'latest_date' => $latestDate,
                'days' => $days
            ]);
        }

        // STEP 2: Get history for ONLY these products (much smaller dataset)
        $inClause = implode(',', array_map('intval', $productIds));
        $dateInClause = implode(',', array_map(function($d) use ($conn) {
            return $conn->quote($d);
        }, $last7Dates));

        $historySql = "
            SELECT
                product_id,
                DATE(collected_at) as collection_date,
                quantity
            FROM daily_stocks
            WHERE boutique_id = :boutique_id
                AND product_id IN ({$inClause})
                AND DATE(collected_at) IN ({$dateInClause})
            ORDER BY product_id, collected_at
        ";

        $historyResult = $conn->executeQuery($historySql, [
            'boutique_id' => $boutique->getId()
        ]);
        $historyData = $historyResult->fetchAllAssociative();

        // Index history by product_id and date
        $historyIndex = [];
        foreach ($historyData as $row) {
            $historyIndex[$row['product_id']][$row['collection_date']] = $row['quantity'];
        }

        // Calculate out of stock days for each product
        $outOfStockDays = [];
        foreach ($historyIndex as $productId => $dateQuantities) {
            $outOfStockCount = 0;
            foreach ($dateQuantities as $quantity) {
                if ($quantity == 0) {
                    $outOfStockCount++;
                }
            }
            $outOfStockDays[$productId] = $outOfStockCount;
        }

        // Calculate total days in stock for each product
        $totalDaysInStock = [];
        $sql = "
            SELECT product_id, COUNT(DISTINCT DATE(collected_at)) as total_days
            FROM daily_stocks
            WHERE boutique_id = :boutique_id
                AND product_id IN ({$inClause})
            GROUP BY product_id
        ";
        $totalDaysResult = $conn->executeQuery($sql, ['boutique_id' => $boutique->getId()]);
        foreach ($totalDaysResult->fetchAllAssociative() as $row) {
            $totalDaysInStock[$row['product_id']] = $row['total_days'];
        }

        // Calculate last restock date for each product (when quantity increased)
        $lastRestockDates = [];
        $sql = "
            WITH daily_changes AS (
                SELECT
                    product_id,
                    DATE(collected_at) as stock_date,
                    quantity,
                    LAG(quantity) OVER (PARTITION BY product_id ORDER BY collected_at) as prev_quantity
                FROM daily_stocks
                WHERE boutique_id = :boutique_id
                    AND product_id IN ({$inClause})
            )
            SELECT
                product_id,
                MAX(stock_date) as last_restock_date
            FROM daily_changes
            WHERE quantity > COALESCE(prev_quantity, 0)
            GROUP BY product_id
        ";
        $restockResult = $conn->executeQuery($sql, ['boutique_id' => $boutique->getId()]);
        foreach ($restockResult->fetchAllAssociative() as $row) {
            $lastRestockDates[$row['product_id']] = $row['last_restock_date'];
        }

        // Get sales data if requested
        $salesData = [];
        if ($showSales || $showRevenue) {
            $salesData = $this->orderRepository->getSalesByProductAndDay(
                $boutique->getId(),
                $productIds,
                $last7Dates
            );
        }

        // Merge history into products
        foreach ($products as &$product) {
            $productId = $product['product_id'];
            foreach ($last7Dates as $index => $date) {
                $product["day_{$index}_quantity"] = $historyIndex[$productId][$date] ?? null;

                // Add sales data if available
                if (isset($salesData[$productId][$date])) {
                    $product["day_{$index}_sales_qty"] = $salesData[$productId][$date]['quantity'];
                    $product["day_{$index}_sales_revenue"] = $salesData[$productId][$date]['revenue'];
                } else {
                    $product["day_{$index}_sales_qty"] = 0;
                    $product["day_{$index}_sales_revenue"] = 0;
                }
            }
            // Add out of stock days count
            $product['out_of_stock_days'] = $outOfStockDays[$productId] ?? 0;
            // Add total days in stock
            $product['total_days_in_stock'] = $totalDaysInStock[$productId] ?? 0;
            // Add last restock date
            $product['last_restock_date'] = $lastRestockDates[$productId] ?? null;
        }

        // Apply excludeOutOfStockDays filter if specified
        if ($excludeOutOfStockDays !== null && $excludeOutOfStockDays > 0) {
            $products = array_filter($products, function($product) use ($excludeOutOfStockDays) {
                return $product['out_of_stock_days'] < $excludeOutOfStockDays;
            });
            // Re-index the array to avoid gaps in keys
            $products = array_values($products);
            // Update total count to reflect filtered results
            $totalCount = count($products);
        }

        // Format dates for template
        $formattedDates = [];
        foreach ($last7Dates as $index => $date) {
            $dateObj = new \DateTimeImmutable($date);
            $formattedDates[$index] = [
                'date' => $dateObj,
                'label' => $dateObj->format('d/m')
            ];
        }

        return [
            'stocks' => $products,
            'dates' => $formattedDates,
            'latest_date' => $latestDateTime,
            'total_count' => $totalCount
        ];
    }

    /**
     * Get distinct categories for a boutique
     */
    public function getDistinctCategories(
        Boutique $boutique,
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate = null
    ): array {
        $conn = $this->getEntityManager()->getConnection();

        // If date range is provided, get categories from that range
        if ($startDate && $endDate) {
            $categories = $conn->fetchFirstColumn('
                SELECT DISTINCT category
                FROM daily_stocks
                WHERE boutique_id = :boutique_id
                    AND collected_at >= :start_date
                    AND collected_at <= :end_date
                    AND category IS NOT NULL
                    AND category != \'\'
                ORDER BY category ASC
            ', [
                'boutique_id' => $boutique->getId(),
                'start_date' => $startDate->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
                'end_date' => $endDate->setTime(23, 59, 59)->format('Y-m-d H:i:s')
            ]);

            return $categories;
        }

        // Get the latest collection date
        $latestDate = $conn->fetchOne('
            SELECT MAX(collected_at)
            FROM daily_stocks
            WHERE boutique_id = :boutique_id
        ', ['boutique_id' => $boutique->getId()]);

        if (!$latestDate) {
            return [];
        }

        // Get distinct categories from latest snapshot
        $categories = $conn->fetchFirstColumn('
            SELECT DISTINCT category
            FROM daily_stocks
            WHERE boutique_id = :boutique_id
                AND collected_at = :latest_date
                AND category IS NOT NULL
                AND category != \'\'
            ORDER BY category ASC
        ', [
            'boutique_id' => $boutique->getId(),
            'latest_date' => $latestDate
        ]);

        return $categories;
    }

    /**
     * Get order data for a specific product by period
     */
    public function findProductOrdersByPeriod(
        Boutique $boutique,
        int $productId,
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate = null,
        ?int $days = null
    ): array {
        $conn = $this->getEntityManager()->getConnection();

        // Determine date range
        if ($startDate && $endDate) {
            $startFormatted = $startDate->setTime(0, 0, 0)->format('Y-m-d');
            $endFormatted = $endDate->setTime(23, 59, 59)->format('Y-m-d');
        } else {
            // Use number of days
            $daysToUse = $days ?? 30;
            $endDate = new \DateTimeImmutable();
            $startDate = $endDate->modify("-{$daysToUse} days");
            $startFormatted = $startDate->format('Y-m-d');
            $endFormatted = $endDate->format('Y-m-d');
        }

        // Get sales data grouped by day for this specific product
        $sql = "
            SELECT
                DATE(o.order_date) as order_date,
                SUM(oi.quantity) as quantity,
                SUM(oi.total_price) as revenue
            FROM orders o
            INNER JOIN order_items oi ON o.id = oi.order_id
            WHERE o.boutique_id = :boutique_id
                AND oi.product_id = :product_id
                AND DATE(o.order_date) >= :start_date
                AND DATE(o.order_date) <= :end_date
                AND o.current_state NOT IN ('6', '7', '8')
            GROUP BY DATE(o.order_date)
            ORDER BY order_date ASC
        ";

        $result = $conn->executeQuery($sql, [
            'boutique_id' => $boutique->getId(),
            'product_id' => $productId,
            'start_date' => $startFormatted,
            'end_date' => $endFormatted
        ]);

        $data = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $data[$row['order_date']] = [
                'quantity' => (int) $row['quantity'],
                'revenue' => (float) $row['revenue']
            ];
        }

        return $data;
    }
}
