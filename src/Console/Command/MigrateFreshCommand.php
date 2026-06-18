<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;
use SybaseORM\Migration\MigrationManager;

/**
 * Drops all tables and re-runs all migrations from scratch.
 *
 * WARNING: This is destructive! Only for development environments.
 *
 * Usage: migrate:fresh [--force]
 */
final class MigrateFreshCommand implements CommandInterface
{
    public function __construct(
        private readonly MigrationManager $migrationManager,
    ) {}

    public function getName(): string
    {
        return 'migrate:fresh';
    }

    public function getDescription(): string
    {
        return 'Drop all tables and re-run all migrations (destructive!)';
    }

    public function execute(array $args): int
    {
        if (!in_array('--force', $args, true)) {
            IO::warning('This will DROP ALL TABLES and re-run migrations.');
            IO::warning('Add --force to confirm.');

            return 1;
        }

        IO::output('Dropping all tables and re-running migrations...');

        try {
            // Rollback all executed migrations
            $status = $this->migrationManager->getStatus();
            $rolledBack = 0;

            for ($i = 0; $i < $status['executed']; $i++) {
                try {
                    $this->migrationManager->rollback();
                    $rolledBack++;
                } catch (\Throwable) {
                    break;
                }
            }

            if ($rolledBack > 0) {
                IO::info(sprintf('Rolled back %d migration(s).', $rolledBack));
            }

            // Re-run all migrations
            $executed = $this->migrationManager->migrate();
            IO::success(sprintf('Fresh migration complete. %d migration(s) executed.', count($executed)));

            return 0;
        } catch (\Throwable $e) {
            IO::error('Fresh migration failed: ' . $e->getMessage());

            return 1;
        }
    }
}
