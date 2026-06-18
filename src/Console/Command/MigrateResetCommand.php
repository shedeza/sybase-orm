<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;
use SybaseORM\Migration\MigrationManager;

/**
 * Rolls back ALL executed migrations.
 *
 * Usage: migrate:reset [--force]
 */
final class MigrateResetCommand implements CommandInterface
{
    public function __construct(
        private readonly MigrationManager $migrationManager,
    ) {}

    public function getName(): string
    {
        return 'migrate:reset';
    }

    public function getDescription(): string
    {
        return 'Rollback ALL executed migrations';
    }

    public function execute(array $args): int
    {
        if (!in_array('--force', $args, true)) {
            IO::warning('This will rollback ALL executed migrations.');
            IO::warning('Add --force to confirm.');

            return 1;
        }

        IO::output('Rolling back all migrations...');

        try {
            $status = $this->migrationManager->getStatus();
            $rolledBack = 0;

            for ($i = 0; $i < $status['executed']; $i++) {
                $this->migrationManager->rollback();
                $rolledBack++;
            }

            IO::success(sprintf('Rolled back %d migration(s).', $rolledBack));

            return 0;
        } catch (\Throwable $e) {
            IO::error('Reset failed: ' . $e->getMessage());

            return 1;
        }
    }
}
