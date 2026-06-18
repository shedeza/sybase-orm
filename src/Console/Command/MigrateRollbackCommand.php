<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;
use SybaseORM\Migration\MigrationManager;

/**
 * Rolls back the last executed migration.
 */
final class MigrateRollbackCommand implements CommandInterface
{
    public function __construct(
        private readonly MigrationManager $migrationManager,
    ) {}

    public function getName(): string
    {
        return 'migrate:rollback';
    }

    public function getDescription(): string
    {
        return 'Rollback the last migration';
    }

    public function execute(array $args): int
    {
        IO::output('Rolling back last migration...');

        try {
            $this->migrationManager->rollback();
            IO::success('Rollback successful.');

            return 0;
        } catch (\Throwable $e) {
            IO::error('Rollback failed: ' . $e->getMessage());

            return 1;
        }
    }
}
