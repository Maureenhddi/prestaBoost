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
    public function __construct(ManagerRegistry $registry)
    {
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

        return $this->createQueryBuilder('ds')
            ->where('ds.boutique = :boutique')
            ->andWhere('ds.collectedAt = :latestDate')
            ->andWhere('ds.quantity > 0')
            ->andWhere('ds.quantity < 10')
            ->setParameter('boutique', $boutique)
            ->setParameter('latestDate', $latestDate)
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
     * Get stocks with last N days history for each product
     * Returns product info with stock quantities for each of the last N days
     *
     * Optimized version using 2-step approach to avoid slow JOINs on large tables
     */
    public function findStocksWithLast7Days(
        Boutique $boutique,
        int $page = 1,
        int $perPage = 50,
        ?string $filter = 'all',
        ?string $search = null,
        int $days = 7
    ): array {
        $conn = $this->getEntityManager()->getConnection();

        // Get the latest collection date
        $latestDate = $conn->fetchOne('
            SELECT MAX(collected_at)
            FROM daily_stocks
            WHERE boutique_id = :boutique_id
        ', ['boutique_id' => $boutique->getId()]);

        if (!$latestDate) {
            return ['stocks' => [], 'dates' => [], 'latest_date' => null];
        }

        $latestDateTime = new \DateTimeImmutable($latestDate);
        $offset = ($page - 1) * $perPage;

        // Build WHERE clauses for filters
        $filterClause = '';
        $params = [
            'boutique_id' => $boutique->getId(),
            'latest_date' => $latestDate,
            'limit' => $perPage,
            'offset' => $offset
        ];

        if ($search) {
            $filterClause .= " AND (name ILIKE :search OR reference ILIKE :search)";
            $params['search'] = '%' . $search . '%';
        }

        // Apply stock level filter
        if ($filter === 'outofstock') {
            $filterClause .= " AND quantity = 0";
        } elseif ($filter === 'low') {
            $filterClause .= " AND quantity > 0 AND quantity < 10";
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
        $totalCount = $conn->fetchOne($countSql, $countParams);

        // STEP 1: Get filtered products from current snapshot only (very fast)
        $sql = "
            SELECT
                product_id,
                name,
                reference,
                quantity as current_quantity
            FROM daily_stocks
            WHERE boutique_id = :boutique_id
                AND collected_at = :latest_date
                {$filterClause}
            ORDER BY name ASC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery($params);
        $products = $result->fetchAllAssociative();

        if (empty($products)) {
            return ['stocks' => [], 'dates' => [], 'latest_date' => $latestDateTime, 'total_count' => 0];
        }

        // Get product IDs
        $productIds = array_column($products, 'product_id');

        // Ensure days is between 1 and 7
        $days = max(1, min(7, $days));

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

        // Merge history into products
        foreach ($products as &$product) {
            $productId = $product['product_id'];
            foreach ($last7Dates as $index => $date) {
                $product["day_{$index}_quantity"] = $historyIndex[$productId][$date] ?? null;
            }
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
}
