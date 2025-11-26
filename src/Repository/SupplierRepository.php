<?php

namespace App\Repository;

use App\Entity\Supplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Supplier>
 */
class SupplierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Supplier::class);
    }

    public function save(Supplier $supplier, bool $flush = false): void
    {
        $this->getEntityManager()->persist($supplier);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Supplier $supplier, bool $flush = false): void
    {
        $this->getEntityManager()->remove($supplier);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find active suppliers for a boutique
     */
    public function findActiveByBoutique(int $boutiqueId): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.boutique = :boutiqueId')
            ->andWhere('s.active = true')
            ->setParameter('boutiqueId', $boutiqueId)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
