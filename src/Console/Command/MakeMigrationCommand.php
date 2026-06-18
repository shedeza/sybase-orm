<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;

/**
 * Creates an empty migration file for manual SQL writing.
 *
 * Usage: make:migration [description]
 *   Example: make:migration add_index_to_users_email
 */
final class MakeMigrationCommand implements CommandInterface
{
    public function __construct(
        private readonly string $migrationsDirectory,
    ) {}

    public function getName(): string
    {
        return 'make:migration';
    }

    public function getDescription(): string
    {
        return 'Create an empty migration file';
    }

    public function execute(array $args): int
    {
        $description = implode('_', $args) ?: 'custom_migration';
        $description = preg_replace('/[^a-zA-Z0-9_]/', '_', $description);

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

        IO::success('Migration created: ' . $filename);
        IO::info('Path: ' . $filePath);

        return 0;
    }
}
