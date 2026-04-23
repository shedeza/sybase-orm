<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Migration;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Exception\MigrationException;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\Migration\MigrationManager;

final class MigrationManagerTest extends TestCase
{
    private ConnectionManagerInterface&MockObject $connection;
    private MetadataReaderInterface&MockObject $metadataReader;
    private DialectInterface&MockObject $dialect;
    private string $migrationsDir;
    private MigrationManager $manager;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(ConnectionManagerInterface::class);
        $this->metadataReader = $this->createMock(MetadataReaderInterface::class);
        $this->dialect = $this->createMock(DialectInterface::class);
        $this->migrationsDir = sys_get_temp_dir() . '/sybase_orm_test_migrations_' . uniqid();

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => "[{$id}]");

        $this->manager = new MigrationManager(
            $this->connection,
            $this->metadataReader,
            $this->dialect,
            $this->migrationsDir,
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->migrationsDir)) {
            $files = glob($this->migrationsDir . '/*');
            if ($files) {
                array_map('unlink', $files);
            }
            rmdir($this->migrationsDir);
        }
    }

    // -------------------------------------------------------
    // generateMigration — Validates: Requirement 12.1, 12.4
    // -------------------------------------------------------

    public function testGenerateMigrationCreatesFileForNewTable(): void
    {
        $metadata = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [
                new ColumnMetadata('id', 'id', 'integer', false, null, null, null, true, 'IDENTITY'),
                new ColumnMetadata('name', 'name', 'string', false, 255),
                new ColumnMetadata('email', 'email', 'string', true, 100),
            ],
            idField: 'id',
        );

        $this->metadataReader->method('getClassMetadata')
            ->with('App\\Entity\\User')
            ->willReturn($metadata);

        // tableExists('users') → false (no row returned)
        $stmt = $this->createFetchMock([]);
        $this->connection->method('executeQuery')->willReturn($stmt);

        $filePath = $this->manager->generateMigration(['App\\Entity\\User']);

        $this->assertNotNull($filePath);
        $this->assertFileExists($filePath);

        $migration = require $filePath;
        $this->assertArrayHasKey('up', $migration);
        $this->assertArrayHasKey('down', $migration);
        $this->assertCount(1, $migration['up']);
        $this->assertCount(1, $migration['down']);

        $createSql = $migration['up'][0];
        $this->assertStringContainsString('CREATE TABLE [users]', $createSql);
        $this->assertStringContainsString('[id] INT IDENTITY NOT NULL', $createSql);
        $this->assertStringContainsString('[name] VARCHAR(255) NOT NULL', $createSql);
        $this->assertStringContainsString('[email] VARCHAR(100) NULL', $createSql);

        $dropSql = $migration['down'][0];
        $this->assertStringContainsString('DROP TABLE [users]', $dropSql);
    }

    public function testGenerateMigrationReturnsNullWhenNoChanges(): void
    {
        $metadata = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [
                new ColumnMetadata('id', 'id', 'integer', false, null, null, null, true, 'IDENTITY'),
                new ColumnMetadata('name', 'name', 'string', false, 255),
            ],
            idField: 'id',
        );

        $this->metadataReader->method('getClassMetadata')
            ->with('App\\Entity\\User')
            ->willReturn($metadata);

        // Call 1: tableExists('users') → true (row found)
        // Call 2: getExistingColumns('users') → id, name (all present)
        $callIndex = 0;
        $this->connection->method('executeQuery')
            ->willReturnCallback(function () use (&$callIndex) {
                $callIndex++;
                if ($callIndex === 1) {
                    // tableExists → row found
                    return $this->createFetchMock([['1' => 1]]);
                }
                // getExistingColumns → all columns exist
                return $this->createFetchMock([
                    ['name' => 'id'],
                    ['name' => 'name'],
                ]);
            });

        $filePath = $this->manager->generateMigration(['App\\Entity\\User']);
        $this->assertNull($filePath);
    }

    public function testGenerateMigrationCreatesAlterForNewColumns(): void
    {
        $metadata = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [
                new ColumnMetadata('id', 'id', 'integer', false, null, null, null, true, 'IDENTITY'),
                new ColumnMetadata('name', 'name', 'string', false, 255),
                new ColumnMetadata('email', 'email', 'string', true, 100),
            ],
            idField: 'id',
        );

        $this->metadataReader->method('getClassMetadata')
            ->with('App\\Entity\\User')
            ->willReturn($metadata);

        $callIndex = 0;
        $this->connection->method('executeQuery')
            ->willReturnCallback(function () use (&$callIndex) {
                $callIndex++;
                if ($callIndex === 1) {
                    return $this->createFetchMock([['1' => 1]]);
                }
                return $this->createFetchMock([
                    ['name' => 'id'],
                    ['name' => 'name'],
                ]);
            });

        $filePath = $this->manager->generateMigration(['App\\Entity\\User']);
        $this->assertNotNull($filePath);

        $migration = require $filePath;
        $this->assertCount(1, $migration['up']);
        $this->assertCount(1, $migration['down']);

        $this->assertStringContainsString('ALTER TABLE [users] ADD [email] VARCHAR(100) NULL', $migration['up'][0]);
        $this->assertStringContainsString('ALTER TABLE [users] DROP [email]', $migration['down'][0]);
    }

    public function testGenerateMigrationMapsColumnTypesCorrectly(): void
    {
        $metadata = new ClassMetadata(
            entityClass: 'App\\Entity\\Product',
            tableName: 'products',
            columns: [
                new ColumnMetadata('id', 'id', 'integer', false, null, null, null, true, 'IDENTITY'),
                new ColumnMetadata('price', 'price', 'decimal', false, null, 10, 2),
                new ColumnMetadata('active', 'active', 'boolean', false),
                new ColumnMetadata('createdAt', 'created_at', 'datetime', false),
                new ColumnMetadata('weight', 'weight', 'float', true),
                new ColumnMetadata('description', 'description', 'text', true),
            ],
            idField: 'id',
        );

        $this->metadataReader->method('getClassMetadata')
            ->with('App\\Entity\\Product')
            ->willReturn($metadata);

        // Table does not exist
        $stmt = $this->createFetchMock([]);
        $this->connection->method('executeQuery')->willReturn($stmt);

        $filePath = $this->manager->generateMigration(['App\\Entity\\Product']);
        $migration = require $filePath;

        $createSql = $migration['up'][0];
        $this->assertStringContainsString('[price] DECIMAL(10,2) NOT NULL', $createSql);
        $this->assertStringContainsString('[active] BIT NOT NULL', $createSql);
        $this->assertStringContainsString('[created_at] DATETIME NOT NULL', $createSql);
        $this->assertStringContainsString('[weight] FLOAT NULL', $createSql);
        $this->assertStringContainsString('[description] TEXT NULL', $createSql);
    }

    // -------------------------------------------------------
    // migrate — Validates: Requirement 12.2, 12.5
    // -------------------------------------------------------

    public function testMigrateExecutesPendingMigrationsAndRegistersVersion(): void
    {
        $this->createMigrationFile('20240101120000', [
            'up' => ['CREATE TABLE [users] ([id] INT NOT NULL)'],
            'down' => ['DROP TABLE [users]'],
        ]);

        $this->connection->method('executeQuery')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'sysobjects')) {
                    return $this->createFetchMock([['1' => 1]]);
                }
                // getExecutedVersions → no versions
                return $this->createFetchMock([]);
            });

        $executedSql = [];
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$executedSql) {
                $executedSql[] = $sql;
                return 1;
            });

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');

        $result = $this->manager->migrate();

        $this->assertCount(1, $result);
        $this->assertSame('20240101120000', $result[0]);

        $this->assertNotEmpty(array_filter($executedSql, fn($s) => str_contains($s, 'CREATE TABLE [users]')));
        $this->assertNotEmpty(array_filter($executedSql, fn($s) => str_contains($s, '[__migrations]')));
    }

    public function testMigrateRollsBackAndThrowsOnFailure(): void
    {
        $this->createMigrationFile('20240101120000', [
            'up' => ['CREATE TABLE [users] ([id] INT NOT NULL)'],
            'down' => ['DROP TABLE [users]'],
        ]);

        $queryCallIndex = 0;
        $this->connection->method('executeQuery')
            ->willReturnCallback(function (string $sql) use (&$queryCallIndex) {
                $queryCallIndex++;
                if (str_contains($sql, 'sysobjects')) {
                    // tableExists → always true
                    return $this->createFetchMock([['1' => 1]]);
                }
                // getExecutedVersions → no versions
                return $this->createFetchMock([]);
            });

        $this->connection->method('executeStatement')
            ->willThrowException(new \RuntimeException('Sybase error'));

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->never())->method('commit');
        $this->connection->expects($this->once())->method('rollback');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/Migration "20240101120000" failed/');

        $this->manager->migrate();
    }

    public function testMigrateSkipsAlreadyExecutedVersions(): void
    {
        $this->createMigrationFile('20240101120000', [
            'up' => ['CREATE TABLE [users] ([id] INT NOT NULL)'],
            'down' => ['DROP TABLE [users]'],
        ]);
        $this->createMigrationFile('20240102120000', [
            'up' => ['ALTER TABLE [users] ADD [name] VARCHAR(255) NOT NULL'],
            'down' => ['ALTER TABLE [users] DROP [name]'],
        ]);

        $this->connection->method('executeQuery')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'sysobjects')) {
                    return $this->createFetchMock([['1' => 1]]);
                }
                // getExecutedVersions → first migration already done
                return $this->createFetchMock([
                    ['version' => '20240101120000'],
                ]);
            });

        $executedSql = [];
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$executedSql) {
                $executedSql[] = $sql;
                return 1;
            });

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');

        $result = $this->manager->migrate();

        $this->assertCount(1, $result);
        $this->assertSame('20240102120000', $result[0]);

        $this->assertEmpty(array_filter($executedSql, fn($s) => str_contains($s, 'CREATE TABLE [users]')));
        $this->assertNotEmpty(array_filter($executedSql, fn($s) => str_contains($s, 'ALTER TABLE [users] ADD [name]')));
    }

    // -------------------------------------------------------
    // rollback — Validates: Requirement 12.3, 12.5
    // -------------------------------------------------------

    public function testRollbackExecutesDownStatementsAndUnregistersVersion(): void
    {
        $this->createMigrationFile('20240101120000', [
            'up' => ['CREATE TABLE [users] ([id] INT NOT NULL)'],
            'down' => ['DROP TABLE [users]'],
        ]);

        $this->connection->method('executeQuery')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'sysobjects')) {
                    return $this->createFetchMock([['1' => 1]]);
                }
                return $this->createFetchMock([
                    ['version' => '20240101120000'],
                ]);
            });

        $executedSql = [];
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$executedSql) {
                $executedSql[] = $sql;
                return 1;
            });

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');

        $result = $this->manager->rollback();

        $this->assertSame('20240101120000', $result);
        $this->assertNotEmpty(array_filter($executedSql, fn($s) => str_contains($s, 'DROP TABLE [users]')));
        $this->assertNotEmpty(array_filter($executedSql, fn($s) => str_contains($s, 'DELETE FROM [__migrations]')));
    }

    public function testRollbackReturnsNullWhenNoMigrationsExecuted(): void
    {
        $this->connection->method('executeQuery')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'sysobjects')) {
                    return $this->createFetchMock([['1' => 1]]);
                }
                return $this->createFetchMock([]);
            });

        $result = $this->manager->rollback();
        $this->assertNull($result);
    }

    public function testRollbackThrowsWhenMigrationFileMissing(): void
    {
        $this->connection->method('executeQuery')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'sysobjects')) {
                    return $this->createFetchMock([['1' => 1]]);
                }
                return $this->createFetchMock([
                    ['version' => '20240101120000'],
                ]);
            });

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $this->manager->rollback();
    }

    public function testRollbackRollsBackAndThrowsOnFailure(): void
    {
        $this->createMigrationFile('20240101120000', [
            'up' => ['CREATE TABLE [users] ([id] INT NOT NULL)'],
            'down' => ['DROP TABLE [users]'],
        ]);

        $this->connection->method('executeQuery')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'sysobjects')) {
                    return $this->createFetchMock([['1' => 1]]);
                }
                return $this->createFetchMock([
                    ['version' => '20240101120000'],
                ]);
            });

        $this->connection->method('executeStatement')
            ->willThrowException(new \RuntimeException('Cannot drop table'));

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->never())->method('commit');
        $this->connection->expects($this->once())->method('rollback');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/Rollback of migration "20240101120000" failed/');

        $this->manager->rollback();
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function createFetchMock(array $rows): \PDOStatement
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $callIndex = 0;
        $stmt->method('fetch')
            ->willReturnCallback(function () use ($rows, &$callIndex) {
                return $rows[$callIndex++] ?? false;
            });

        return $stmt;
    }

    private function createMigrationFile(string $version, array $migration): void
    {
        if (!is_dir($this->migrationsDir)) {
            mkdir($this->migrationsDir, 0755, true);
        }

        $upExported = var_export($migration['up'], true);
        $downExported = var_export($migration['down'], true);

        $content = "<?php\nreturn [\n    'up' => {$upExported},\n    'down' => {$downExported},\n];\n";

        file_put_contents(
            $this->migrationsDir . '/migration_' . $version . '.php',
            $content
        );
    }
}
