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
}
