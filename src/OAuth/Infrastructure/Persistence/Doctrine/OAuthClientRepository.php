<?php

declare(strict_types=1);

namespace App\OAuth\Infrastructure\Persistence\Doctrine;

use App\OAuth\Domain\OAuthClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthClient>
 */
class OAuthClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthClient::class);
    }
}
