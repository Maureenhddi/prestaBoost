<?php

namespace App\Repository;

use App\Entity\SyncJob;
use App\Entity\Boutique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SyncJob>
 */
class SyncJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncJob::class);
    }

    /**
     * Find active sync job for a boutique
     */
    public function findActiveSyncJob(Boutique $boutique): ?SyncJob
    {
        return $this->createQueryBuilder('s')
            ->where('s.boutique = :boutique')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('boutique', $boutique)
            ->setParameter('statuses', ['pending', 'running'])
            ->orderBy('s.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find recent sync jobs for a boutique
     */
    public function findRecentSyncJobs(Boutique $boutique, int $limit = 10): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.boutique = :boutique')
            ->setParameter('boutique', $boutique)
            ->orderBy('s.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
