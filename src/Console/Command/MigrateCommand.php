<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;
use SybaseORM\Migration\MigrationManager;

/**
 * Runs all pending database migrations.
 */
final class MigrateCommand implements CommandInterface
{
    public function __construct(
        private readonly MigrationManager $migrationManager,
    ) {}

    public function getName(): string
    {
        return 'migrate';
    }

    public function getDescription(): string
    {
        return 'Run all pending migrations';
    }

    public function execute(array $args): int
    {
        IO::output('Running pending migrations...');

        try {
            $executed = $this->migrationManager->migrate();

            if (count($executed) === 0) {
                IO::info('Nothing to migrate. Database is up to date.');
            } else {
                IO::success(sprintf('%d migration(s) executed successfully.', count($executed)));
                foreach ($executed as $version) {
                    IO::info('  • ' . $version);
                }
            }

            return 0;
        } catch (\Throwable $e) {
            IO::error('Migration failed: ' . $e->getMessage());

            return 1;
        }
    }
}
