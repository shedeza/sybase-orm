<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;
use SybaseORM\Migration\MigrationManager;

/**
 * Generates a migration by diffing entity metadata against the database schema.
 *
 * Usage: migrate:generate App\Entity\User App\Entity\Order
 */
final class MigrateGenerateCommand implements CommandInterface
{
    public function __construct(
        private readonly MigrationManager $migrationManager,
    ) {}

    public function getName(): string
    {
        return 'migrate:generate';
    }

    public function getDescription(): string
    {
        return 'Generate migration from entity/schema diff';
    }

    public function execute(array $args): int
    {
        if (empty($args)) {
            IO::error('Usage: migrate:generate App\\Entity\\User [App\\Entity\\Order ...]');

            return 1;
        }

        try {
            $file = $this->migrationManager->generateMigration($args);

            if ($file === null) {
                IO::info('No schema changes detected.');
            } else {
                IO::success('Migration generated: ' . $file);
            }

            return 0;
        } catch (\Throwable $e) {
            IO::error('Generation failed: ' . $e->getMessage());

            return 1;
        }
    }
}
