<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;
use SybaseORM\Migration\MigrationManager;

/**
 * Validates that entity mappings are in sync with the database schema.
 *
 * Usage: schema:validate App\Entity\User App\Entity\Order
 */
final class SchemaValidateCommand implements CommandInterface
{
    public function __construct(
        private readonly MigrationManager $migrationManager,
    ) {}

    public function getName(): string
    {
        return 'schema:validate';
    }

    public function getDescription(): string
    {
        return 'Validate entity mapping vs database schema';
    }

    public function execute(array $args): int
    {
        if (empty($args)) {
            IO::error('Usage: schema:validate App\\Entity\\User [App\\Entity\\Order ...]');

            return 1;
        }

        try {
            $result = $this->migrationManager->preview($args);
            $sql = $result['up'];

            if (empty($sql)) {
                IO::success('Schema is in sync with entity mappings.');

                return 0;
            }

            IO::output('  ✗ Schema is NOT in sync. Differences found:');
            foreach ($sql as $statement) {
                IO::info('  ' . $statement);
            }

            return 1;
        } catch (\Throwable $e) {
            IO::error('Validation failed: ' . $e->getMessage());

            return 1;
        }
    }
}
