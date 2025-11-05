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
}
