<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;
use SybaseORM\ORM\EntityManagerInterface;
use SybaseORM\Fixture\FixtureLoader;
use SybaseORM\Fixture\FixtureInterface;

class SeedCommand implements CommandInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function getName(): string
    {
        return 'orm:db:seed';
    }

    public function getDescription(): string
    {
        return 'Seeds the database with records';
    }

    public function execute(array $args): int
    {
        $io = new IO();
        $class = null;
        $dir = 'database/seeds';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--class=')) {
                $class = substr($arg, 8);
            } elseif (str_starts_with($arg, '--dir=')) {
                $dir = substr($arg, 6);
            }
        }

        $loader = new FixtureLoader();

        if ($class !== null) {
            if (!class_exists($class) || !is_subclass_of($class, FixtureInterface::class)) {
                $io->error("Class $class does not exist or does not implement FixtureInterface");
                return 1;
            }
            $loader->addFixture(new $class());
            $io->info("Seeding class: $class");
        } else {
            $io->info("Seeding from directory: $dir");
            $loader->loadFromDirectory($dir);
        }

        try {
            $loader->execute($this->entityManager);
            $io->success('Database seeded successfully!');
            return 0;
        } catch (\Throwable $e) {
            $io->error('Seeding failed: ' . $e->getMessage());
            return 1;
        }
    }
}
