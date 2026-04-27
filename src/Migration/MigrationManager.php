<?php

declare(strict_types=1);

namespace SybaseORM\Migration;

use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Exception\MigrationException;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;

/**
 * Manages database schema evolution through versioned migration files.
 *
 * Compares entity metadata with the current schema, generates migration files
 * with Sybase ASE SQL, executes migrations within transactions, and supports rollback.
 */
final class MigrationManager
{
    private const MIGRATIONS_TABLE = '__migrations';

    public function __construct(
        private readonly ConnectionManagerInterface $connection,
        private readonly MetadataReaderInterface $metadataReader,
        private readonly DialectInterface $dialect,
        private readonly string $migrationsDirectory,
    ) {
    }

    /**
     * Generates a migration file by comparing entity metadata with the expected schema.
     *
     * @param string[] $entityClasses Fully qualified class names of entities to inspect
     * @return string|null The generated migration file path, or null if no changes detected
     */
    public function generateMigration(array $entityClasses): ?string
    {
        $upStatements = [];
        $downStatements = [];

        foreach ($entityClasses as $entityClass) {
            $metadata = $this->metadataReader->getClassMetadata($entityClass);
            $qualifiedTableName = $metadata->getQualifiedTableName();

            if ($this->tableExists($metadata->tableName)) {
                [$alterUp, $alterDown] = $this->generateAlterStatements($metadata);
                $upStatements = array_merge($upStatements, $alterUp);
                $downStatements = array_merge($downStatements, $alterDown);
            } else {
                $upStatements[] = $this->generateCreateTableSQL($metadata);
                $downStatements[] = $this->generateDropTableSQL($qualifiedTableName);
            }
        }

        if (empty($upStatements)) {
            return null;
        }

        return $this->writeMigrationFile($upStatements, $downStatements);
    }

    /**
     * Executes all pending migrations within transactions.
     * Registers each version in the control table on success.
     * On failure, rolls back and throws MigrationException.
     *
     * @return string[] List of executed migration versions
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $executed = $this->getExecutedVersions();
        $pending = $this->getPendingMigrations($executed);

        $executedVersions = [];

        foreach ($pending as $version => $filePath) {
            $version = (string) $version;
            $migration = $this->loadMigrationFile($filePath);

            $this->connection->beginTransaction();
            try {
                foreach ($migration['up'] as $sql) {
                    $this->connection->executeStatement($sql);
                }
                $this->registerVersion($version);
                $this->connection->commit();
                $executedVersions[] = $version;
            } catch (\Throwable $e) {
                $this->connection->rollback();
                throw new MigrationException(
                    sprintf(
                        'Migration "%s" failed: %s',
                        $version,
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }
        }

        return $executedVersions;
    }

    /**
     * Rolls back the last executed migration.
     * Executes the inverse (down) SQL statements and removes the version from the control table.
     *
     * @return string|null The rolled-back version, or null if no migrations to rollback
     */
    public function rollback(): ?string
    {
        $this->ensureMigrationsTable();
        $executed = $this->getExecutedVersions();

        if (empty($executed)) {
            return null;
        }

        $lastVersion = end($executed);
        $filePath = $this->getMigrationFilePath($lastVersion);

        if (!file_exists($filePath)) {
            throw new MigrationException(
                sprintf('Migration file for version "%s" not found.', $lastVersion)
            );
        }

        $migration = $this->loadMigrationFile($filePath);

        $this->connection->beginTransaction();
        try {
            foreach ($migration['down'] as $sql) {
                $this->connection->executeStatement($sql);
            }
            $this->unregisterVersion($lastVersion);
            $this->connection->commit();

            return $lastVersion;
        } catch (\Throwable $e) {
            $this->connection->rollback();
            throw new MigrationException(
                sprintf(
                    'Rollback of migration "%s" failed: %s',
                    $lastVersion,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
    }

    /**
     * Returns the list of executed migration versions.
     *
     * @return string[]
     */
    public function getExecutedVersions(): array
    {
        $this->ensureMigrationsTable();

        $stmt = $this->connection->executeQuery(
            sprintf(
                'SELECT %s FROM %s ORDER BY %s ASC',
                $this->dialect->quoteIdentifier('version'),
                $this->dialect->quoteIdentifier(self::MIGRATIONS_TABLE),
                $this->dialect->quoteIdentifier('executed_at')
            )
        );

        $versions = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $versions[] = $row['version'];
        }

        return $versions;
    }

    /**
     * Checks if a table exists in the database.
     */
    private function tableExists(string $tableName): bool
    {
        $stmt = $this->connection->executeQuery(
            "SELECT 1 FROM sysobjects WHERE name = ? AND type = 'U'",
            [$tableName]
        );

        return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
    }

    /**
     * Generates CREATE TABLE SQL from entity metadata.
     */
    private function generateCreateTableSQL(ClassMetadata $metadata): string
    {
        $columnDefs = [];

        foreach ($metadata->columns as $column) {
            $columnDefs[] = $this->buildColumnDefinition($column);
        }

        return sprintf(
            'CREATE TABLE %s (%s)',
            $this->dialect->quoteIdentifier($metadata->getQualifiedTableName()),
            implode(', ', $columnDefs)
        );
    }

    /**
     * Generates DROP TABLE SQL.
     */
    private function generateDropTableSQL(string $tableName): string
    {
        return sprintf(
            'DROP TABLE %s',
            $this->dialect->quoteIdentifier($tableName)
        );
    }

    /**
     * Generates ALTER TABLE statements for schema differences.
     *
     * @return array{0: string[], 1: string[]} [upStatements, downStatements]
     */
    private function generateAlterStatements(ClassMetadata $metadata): array
    {
        $existingColumns = $this->getExistingColumns($metadata->tableName);
        $qualifiedName = $metadata->getQualifiedTableName();
        $upStatements = [];
        $downStatements = [];

        foreach ($metadata->columns as $column) {
            if (!in_array($column->columnName, $existingColumns, true)) {
                $upStatements[] = sprintf(
                    'ALTER TABLE %s ADD %s',
                    $this->dialect->quoteIdentifier($qualifiedName),
                    $this->buildColumnDefinition($column)
                );
                $downStatements[] = sprintf(
                    'ALTER TABLE %s DROP %s',
                    $this->dialect->quoteIdentifier($qualifiedName),
                    $this->dialect->quoteIdentifier($column->columnName)
                );
            }
        }

        return [$upStatements, $downStatements];
    }

    /**
     * Gets existing column names for a table from the database.
     *
     * @return string[]
     */
    private function getExistingColumns(string $tableName): array
    {
        $stmt = $this->connection->executeQuery(
            "SELECT c.name FROM syscolumns c JOIN sysobjects o ON c.id = o.id WHERE o.name = ? AND o.type = 'U'",
            [$tableName]
        );

        $columns = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $columns[] = $row['name'];
        }

        return $columns;
    }

    /**
     * Builds a column definition string for CREATE/ALTER TABLE.
     */
    private function buildColumnDefinition(ColumnMetadata $column): string
    {
        $type = $this->mapColumnType($column);
        $quoted = $this->dialect->quoteIdentifier($column->columnName);
        $nullable = $column->nullable ? 'NULL' : 'NOT NULL';

        $parts = [$quoted, $type];

        if ($column->isId && $column->generatedValue === 'IDENTITY') {
            $parts[] = 'IDENTITY';
        }

        $parts[] = $nullable;

        return implode(' ', $parts);
    }

    /**
     * Maps a ColumnMetadata type to a Sybase ASE SQL type.
     */
    private function mapColumnType(ColumnMetadata $column): string
    {
        return match ($column->type) {
            'integer', 'int' => 'INT',
            'smallint' => 'SMALLINT',
            'tinyint' => 'TINYINT',
            'bigint' => 'BIGINT',
            'string' => sprintf('VARCHAR(%d)', $column->length ?? 255),
            'text' => 'TEXT',
            'boolean', 'bool' => 'BIT',
            'float', 'double' => 'FLOAT',
            'real' => 'REAL',
            'decimal' => sprintf('DECIMAL(%d,%d)', $column->precision ?? 10, $column->scale ?? 2),
            'datetime' => 'DATETIME',
            'date' => 'DATE',
            'time' => 'TIME',
            default => sprintf('VARCHAR(%d)', $column->length ?? 255),
        };
    }

    /**
     * Ensures the migrations control table exists.
     */
    private function ensureMigrationsTable(): void
    {
        $tableName = self::MIGRATIONS_TABLE;

        if (!$this->tableExists($tableName)) {
            $sql = sprintf(
                'CREATE TABLE %s (%s VARCHAR(255) NOT NULL, %s DATETIME NOT NULL)',
                $this->dialect->quoteIdentifier($tableName),
                $this->dialect->quoteIdentifier('version'),
                $this->dialect->quoteIdentifier('executed_at')
            );
            $this->connection->executeStatement($sql);
        }
    }

    /**
     * Registers a migration version in the control table.
     */
    private function registerVersion(string $version): void
    {
        $sql = sprintf(
            'INSERT INTO %s (%s, %s) VALUES (?, GETDATE())',
            $this->dialect->quoteIdentifier(self::MIGRATIONS_TABLE),
            $this->dialect->quoteIdentifier('version'),
            $this->dialect->quoteIdentifier('executed_at')
        );
        $this->connection->executeStatement($sql, [$version]);
    }

    /**
     * Removes a migration version from the control table.
     */
    private function unregisterVersion(string $version): void
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s = ?',
            $this->dialect->quoteIdentifier(self::MIGRATIONS_TABLE),
            $this->dialect->quoteIdentifier('version')
        );
        $this->connection->executeStatement($sql, [$version]);
    }

    /**
     * Gets pending migrations (not yet executed).
     *
     * @param string[] $executedVersions
     * @return array<string, string> version => filePath
     */
    private function getPendingMigrations(array $executedVersions): array
    {
        $allMigrations = $this->getAllMigrationFiles();
        $pending = [];

        foreach ($allMigrations as $version => $filePath) {
            $versionStr = (string) $version;
            if (!in_array($versionStr, $executedVersions, true)) {
                $pending[$versionStr] = $filePath;
            }
        }

        return $pending;
    }

    /**
     * Scans the migrations directory for migration files.
     *
     * @return array<string, string> version => filePath, sorted by version
     */
    private function getAllMigrationFiles(): array
    {
        if (!is_dir($this->migrationsDirectory)) {
            return [];
        }

        $files = glob($this->migrationsDirectory . '/migration_*.php');
        if ($files === false) {
            return [];
        }

        $migrations = [];
        foreach ($files as $file) {
            $basename = basename($file, '.php');
            // Extract version from filename: migration_20240101120000
            $version = str_replace('migration_', '', $basename);
            $migrations[$version] = $file;
        }

        ksort($migrations, SORT_STRING);

        return $migrations;
    }

    /**
     * Gets the file path for a specific migration version.
     */
    private function getMigrationFilePath(string $version): string
    {
        return $this->migrationsDirectory . '/migration_' . $version . '.php';
    }

    /**
     * Loads a migration file and returns its up/down SQL arrays.
     *
     * @return array{up: string[], down: string[]}
     */
    private function loadMigrationFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new MigrationException(
                sprintf('Migration file not found: %s', $filePath)
            );
        }

        $migration = require $filePath;

        if (!is_array($migration) || !isset($migration['up']) || !isset($migration['down'])) {
            throw new MigrationException(
                sprintf('Invalid migration file format: %s. Must return array with "up" and "down" keys.', $filePath)
            );
        }

        return $migration;
    }

    /**
     * Writes a migration file with the given up/down SQL statements.
     *
     * @param string[] $upStatements
     * @param string[] $downStatements
     * @return string The generated file path
     */
    private function writeMigrationFile(array $upStatements, array $downStatements): string
    {
        if (!is_dir($this->migrationsDirectory)) {
            mkdir($this->migrationsDirectory, 0755, true);
        }

        $version = date('YmdHis');
        $filePath = $this->getMigrationFilePath($version);

        $upExported = $this->exportSqlArray($upStatements);
        $downExported = $this->exportSqlArray($downStatements);

        $content = <<<PHP
<?php

declare(strict_types=1);

/**
 * Migration generated at {$version}.
 */
return [
    'up' => {$upExported},
    'down' => {$downExported},
];
PHP;

        file_put_contents($filePath, $content);

        return $filePath;
    }

    /**
     * Exports an array of SQL strings to a PHP array literal.
     */
    private function exportSqlArray(array $statements): string
    {
        if (empty($statements)) {
            return '[]';
        }

        $lines = ["[\n"];
        foreach ($statements as $sql) {
            $escaped = str_replace("'", "\\'", $sql);
            $lines[] = "        '{$escaped}',\n";
        }
        $lines[] = '    ]';

        return implode('', $lines);
    }
}
