<?php

declare(strict_types=1);

namespace SybaseORM\Testing;

use SybaseORM\ORM\EntityManagerInterface;

/**
 * Executes database seeders in order.
 *
 * Usage:
 *     $runner = new SeederRunner($em);
 *     $runner->run([
 *         new RoleSeeder(),
 *         new UserSeeder(),
 *         new OrderSeeder(),
 *     ]);
 *
 *     // Or run a single seeder:
 *     $runner->runOne(new UserSeeder());
 */
final class SeederRunner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Runs multiple seeders in order within a transaction.
     *
     * @param SeederInterface[] $seeders
     */
    public function run(array $seeders): void
    {
        $this->entityManager->beginTransaction();

        try {
            foreach ($seeders as $seeder) {
                $seeder->run($this->entityManager);
            }

            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            throw $e;
        }
    }

    /**
     * Runs a single seeder.
     */
    public function runOne(SeederInterface $seeder): void
    {
        $this->run([$seeder]);
    }
}
