<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\Migration\MigrationManager;

/**
 * Generates a migration file by comparing ALL entity mappings against the database.
 *
 * Automatically discovers schema differences (new tables, new columns, removed columns)
 * and writes a migration file with the appropriate SQL.
 *
 * Usage:
 *     make:migration                          # Auto-diff all entities vs DB
 *     make:migration add_users_table          # With custom description
 *     make:migration --empty                  # Create empty file for manual SQL
 */
final class MakeMigrationCommand implements CommandInterface
{
    /**
     * @param MigrationManager $migrationManager
     * @param string $migrationsDirectory
     * @param string[] $entityClasses All registered entity FQCNs
     */
    public function __construct(
        private readonly MigrationManager $migrationManager,
        private readonly string $migrationsDirectory,
        private readonly array $entityClasses = [],
    ) {}

    public function getName(): string
    {
        return 'make:migration';
    }

    public function getDescription(): string
    {
        return 'Generate migration from entity/DB diff (or --empty for manual)';
    }

    public function execute(array $args): int
    {
        // --empty flag: create empty migration for manual SQL
        if (in_array('--empty', $args, true)) {
            $filteredArgs = array_values(array_filter($args, fn($a) => $a !== '--empty'));

            return $this->createEmptyMigration($filteredArgs);
        }

        // Auto-diff mode: compare all entities against DB schema
        if (empty($this->entityClasses)) {
            IO::error('No entity classes registered. Configure entity_directories or entity_classes in sybase-orm.config.php.');

            return 1;
        }

        $description = implode('_', $args) ?: 'auto';

        IO::output(sprintf('Comparing %d entity(ies) against database schema...', count($this->entityClasses)));

        try {
            $file = $this->migrationManager->generateMigration($this->entityClasses);

            if ($file === null) {
                IO::info('No schema changes detected. Database is in sync.');

                return 0;
            }

            IO::success('Migration generated: ' . basename($file));
            IO::info('Path: ' . $file);

            // Show preview of what was generated
            $result = $this->migrationManager->preview($this->entityClasses);
            $upSql = $result['up'];

            if (!empty($upSql)) {
                IO::output('');
                IO::output('  SQL (up):');
                foreach ($upSql as $statement) {
                    IO::info('  ' . $statement);
                }
            }

            return 0;
        } catch (\Throwable $e) {
            IO::error('Migration generation failed: ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * Creates an empty migration file for manual SQL writing.
     *
     * @param string[] $args Remaining args used as description
     */
    private function createEmptyMigration(array $args): int
    {
        $description = implode('_', $args) ?: 'custom_migration';
        $description = (string) preg_replace('/[^a-zA-Z0-9_]/', '_', $description);

        $version = date('YmdHis');
        $filename = $version . '_' . $description . '.php';
        $filePath = rtrim($this->migrationsDirectory, '/') . '/' . $filename;

        if (!is_dir($this->migrationsDirectory)) {
            mkdir($this->migrationsDirectory, 0o777, true);
        }

        $content = <<<PHP
            <?php

            declare(strict_types=1);

            /**
             * Migration: {$description}
             * Generated at {$version}.
             */
            return [
                'up' => [
                    // Add your SQL statements here
                    // "ALTER TABLE users ADD email_verified BIT DEFAULT 0",
                ],
                'down' => [
                    // Add rollback SQL statements here
                    // "ALTER TABLE users DROP email_verified",
                ],
            ];
            PHP;

        $written = file_put_contents($filePath, $content);

        if ($written === false) {
            IO::error('Failed to write migration file: ' . $filePath);

            return 1;
        }

        IO::success('Empty migration created: ' . $filename);
        IO::info('Path: ' . $filePath);

        return 0;
    }
}
