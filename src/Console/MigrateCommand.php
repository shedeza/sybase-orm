<?php

declare(strict_types=1);

namespace SybaseORM\Console;

use SybaseORM\Migration\MigrationManager;

/**
 * CLI command handler for database migrations.
 *
 * Usage:
 *     php bin/sybase-orm migrate           — Run pending migrations
 *     php bin/sybase-orm migrate:rollback  — Rollback last migration
 *     php bin/sybase-orm migrate:status    — Show migration status
 *     php bin/sybase-orm migrate:generate  — Generate a new migration
 *     php bin/sybase-orm migrate:preview   — Preview SQL without executing
 *     php bin/sybase-orm cache:clear       — Clear metadata and proxy caches
 *     php bin/sybase-orm schema:validate   — Validate entity mapping vs DB schema
 */
final class MigrateCommand
{
    public function __construct(
        private readonly MigrationManager $migrationManager,
        private readonly ?string $proxyDirectory = null,
        private readonly ?string $metadataCacheDir = null,
    ) {}

    /**
     * Dispatches CLI arguments to the appropriate handler.
     *
     * @param string[] $argv Command-line arguments (argv[0] is script name)
     * @return int Exit code (0 = success)
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        return match ($command) {
            'migrate' => $this->migrate(),
            'migrate:rollback' => $this->rollback(),
            'migrate:status' => $this->status(),
            'migrate:generate' => $this->generate($argv),
            'migrate:preview' => $this->preview($argv),
            'cache:clear' => $this->cacheClear(),
            'schema:validate' => $this->schemaValidate($argv),
            'help', '--help', '-h' => $this->help(),
            default => $this->unknown($command),
        };
    }

    private function migrate(): int
    {
        $this->output('Running pending migrations...');

        try {
            $executed = $this->migrationManager->migrate();

            if (count($executed) === 0) {
                $this->output('  Nothing to migrate. Database is up to date.');
            } else {
                $this->output(sprintf('  ✓ %d migration(s) executed successfully.', count($executed)));
            }

            return 0;
        } catch (\Throwable $e) {
            $this->error('Migration failed: ' . $e->getMessage());

            return 1;
        }
    }

    private function rollback(): int
    {
        $this->output('Rolling back last migration...');

        try {
            $this->migrationManager->rollback();
            $this->output('  ✓ Rollback successful.');

            return 0;
        } catch (\Throwable $e) {
            $this->error('Rollback failed: ' . $e->getMessage());

            return 1;
        }
    }

    private function status(): int
    {
        $status = $this->migrationManager->getStatus();

        $this->output('Migration Status:');
        $this->output(sprintf('  Total:    %d', $status['total']));
        $this->output(sprintf('  Executed: %d', $status['executed']));
        $this->output(sprintf('  Pending:  %d', $status['pending']));

        return 0;
    }

    /**
     * @param string[] $argv
     */
    private function generate(array $argv): int
    {
        $entityClasses = array_slice($argv, 2);

        if (empty($entityClasses)) {
            $this->error('Usage: migrate:generate App\\Entity\\User [App\\Entity\\Order ...]');

            return 1;
        }

        try {
            $file = $this->migrationManager->generateMigration($entityClasses);

            if ($file === null) {
                $this->output('  No schema changes detected.');
            } else {
                $this->output('  ✓ Migration generated: ' . $file);
            }

            return 0;
        } catch (\Throwable $e) {
            $this->error('Generation failed: ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * @param string[] $argv
     */
    private function preview(array $argv): int
    {
        $entityClasses = array_slice($argv, 2);

        if (empty($entityClasses)) {
            $this->error('Usage: migrate:preview App\\Entity\\User [App\\Entity\\Order ...]');

            return 1;
        }

        try {
            $result = $this->migrationManager->preview($entityClasses);
            $sql = $result['up'];

            if (empty($sql)) {
                $this->output('  No schema changes detected.');
            } else {
                $this->output('  Preview SQL:');
                foreach ($sql as $statement) {
                    $this->output('    ' . $statement);
                }
            }

            return 0;
        } catch (\Throwable $e) {
            $this->error('Preview failed: ' . $e->getMessage());

            return 1;
        }
    }

    private function cacheClear(): int
    {
        $cleared = 0;

        if ($this->proxyDirectory !== null && is_dir($this->proxyDirectory)) {
            $this->clearDirectory($this->proxyDirectory);
            $cleared++;
            $this->output('  ✓ Proxy cache cleared: ' . $this->proxyDirectory);
        }

        if ($this->metadataCacheDir !== null && is_dir($this->metadataCacheDir)) {
            $this->clearDirectory($this->metadataCacheDir);
            $cleared++;
            $this->output('  ✓ Metadata cache cleared: ' . $this->metadataCacheDir);
        }

        if ($cleared === 0) {
            $this->output('  No cache directories configured.');
        }

        return 0;
    }

    /**
     * @param string[] $argv
     */
    private function schemaValidate(array $argv): int
    {
        $entityClasses = array_slice($argv, 2);

        if (empty($entityClasses)) {
            $this->error('Usage: schema:validate App\\Entity\\User [App\\Entity\\Order ...]');

            return 1;
        }

        try {
            $result = $this->migrationManager->preview($entityClasses);
            $sql = $result['up'];

            if (empty($sql)) {
                $this->output('  ✓ Schema is in sync with entity mappings.');

                return 0;
            }

            $this->output('  ✗ Schema is NOT in sync. Differences found:');
            foreach ($sql as $statement) {
                $this->output('    ' . $statement);
            }

            return 1;
        } catch (\Throwable $e) {
            $this->error('Validation failed: ' . $e->getMessage());

            return 1;
        }
    }

    private function help(): int
    {
        $this->output('Sybase ORM CLI');
        $this->output('');
        $this->output('Commands:');
        $this->output('  migrate            Run all pending migrations');
        $this->output('  migrate:rollback   Rollback the last migration');
        $this->output('  migrate:status     Show migration status');
        $this->output('  migrate:generate   Generate a migration from entity diff');
        $this->output('  migrate:preview    Preview SQL without executing');
        $this->output('  cache:clear        Clear proxy and metadata caches');
        $this->output('  schema:validate    Validate entity mapping vs DB schema');
        $this->output('  help               Show this help');

        return 0;
    }

    private function unknown(string $command): int
    {
        $this->error(sprintf('Unknown command: "%s". Run with "help" for available commands.', $command));

        return 1;
    }

    private function clearDirectory(string $dir): void
    {
        $files = glob($dir . '/*');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function output(string $message): void
    {
        echo $message . PHP_EOL;
    }

    private function error(string $message): void
    {
        fwrite(STDERR, '  ERROR: ' . $message . PHP_EOL);
    }
}
