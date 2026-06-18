<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;
use SybaseORM\Migration\MigrationManager;

/**
 * Shows migration status (total, executed, pending).
 */
final class MigrateStatusCommand implements CommandInterface
{
    public function __construct(
        private readonly MigrationManager $migrationManager,
    ) {}

    public function getName(): string
    {
        return 'migrate:status';
    }

    public function getDescription(): string
    {
        return 'Show migration status';
    }

    public function execute(array $args): int
    {
        $status = $this->migrationManager->getStatus();

        IO::output('Migration Status:');
        IO::info(sprintf('Total:    %d', $status['total']));
        IO::info(sprintf('Executed: %d', $status['executed']));
        IO::info(sprintf('Pending:  %d', $status['pending']));

        return 0;
    }
}
