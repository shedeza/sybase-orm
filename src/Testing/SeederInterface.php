<?php

declare(strict_types=1);

namespace SybaseORM\Testing;

use SybaseORM\ORM\EntityManagerInterface;

/**
 * Interface for database seeders.
 *
 * Seeders populate the database with initial/test data.
 *
 * Usage:
 *     class UserSeeder implements SeederInterface {
 *         public function run(EntityManagerInterface $em): void {
 *             for ($i = 0; $i < 10; $i++) {
 *                 $user = new User();
 *                 $user->name = "User $i";
 *                 $em->persist($user);
 *             }
 *             $em->flush();
 *         }
 *     }
 */
interface SeederInterface
{
    /**
     * Runs the seeder to populate the database.
     */
    public function run(EntityManagerInterface $em): void;
}
