<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM\Fixtures;

use SybaseORM\ORM\EntityManagerInterface;
use SybaseORM\ORM\EntityRepository;

/**
 * Custom repository for testing repositoryClass support.
 */
class CustomCustomerRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $entityManager, string $entityClass)
    {
        parent::__construct($entityManager, $entityClass);
    }

    public function findByNamePrefix(string $prefix): array
    {
        return $this->findBy([]);
    }
}
