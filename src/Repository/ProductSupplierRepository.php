<?php

namespace App\Repository;

use App\Entity\ProductSupplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductSupplier>
 */
class ProductSupplierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductSupplier::class);
    }

    public function save(ProductSupplier $productSupplier, bool $flush = false): void
    {
        $this->getEntityManager()->persist($productSupplier);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find supplier info for a product
     */
    public function findByProduct(int $boutiqueId, int $productId): ?ProductSupplier
    {
        return $this->createQueryBuilder('ps')
            ->andWhere('ps.boutique = :boutiqueId')
            ->andWhere('ps.productId = :productId')
            ->andWhere('ps.isPreferred = true')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('productId', $productId)
            ->orderBy('ps.lastPurchaseDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all suppliers for a product
     */
    public function findAllByProduct(int $boutiqueId, int $productId): array
    {
        return $this->createQueryBuilder('ps')
            ->andWhere('ps.boutique = :boutiqueId')
            ->andWhere('ps.productId = :productId')
            ->setParameter('boutiqueId', $boutiqueId)
            ->setParameter('productId', $productId)
            ->orderBy('ps.isPreferred', 'DESC')
            ->addOrderBy('ps.lastPurchaseDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
