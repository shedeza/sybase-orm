<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;
use SybaseORM\Migration\MigrationManager;

/**
 * Previews SQL that would be generated without executing.
 *
 * Usage: migrate:preview App\Entity\User App\Entity\Order
 */
final class MigratePreviewCommand implements CommandInterface
{
    public function __construct(
        private readonly MigrationManager $migrationManager,
    ) {}

    public function getName(): string
    {
        return 'migrate:preview';
    }

    public function getDescription(): string
    {
        return 'Preview migration SQL without executing';
    }

    public function execute(array $args): int
    {
        if (empty($args)) {
            IO::error('Usage: migrate:preview App\\Entity\\User [App\\Entity\\Order ...]');

            return 1;
        }

        try {
            $result = $this->migrationManager->preview($args);
            $upSql = $result['up'];
            $downSql = $result['down'];

            if (empty($upSql)) {
                IO::info('No schema changes detected.');

                return 0;
            }

            IO::output('  UP (apply):');
            foreach ($upSql as $statement) {
                IO::info('  ' . $statement);
            }

            if (!empty($downSql)) {
                IO::output('');
                IO::output('  DOWN (rollback):');
                foreach ($downSql as $statement) {
                    IO::info('  ' . $statement);
                }
            }

            return 0;
        } catch (\Throwable $e) {
            IO::error('Preview failed: ' . $e->getMessage());

            return 1;
        }
    }
}
