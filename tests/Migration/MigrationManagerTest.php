<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Migration;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Migration\MigrationManager;

/**
 * @covers \SybaseORM\Migration\MigrationManager
 */
final class MigrationManagerTest extends TestCase
{
    private MigrationManager $migrationManager;
    private ConnectionManagerInterface&MockObject $connectionManager;
    private MetadataReaderInterface&MockObject $metadataReader;
    private DialectInterface $dialect;

    protected function setUp(): void
    {
        $this->connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $this->metadataReader = $this->createMock(MetadataReaderInterface::class);
        $this->dialect = new \SybaseORM\Dialect\SybaseDialect();

        $this->migrationManager = new MigrationManager(
            $this->connectionManager,
            $this->metadataReader,
            $this->dialect,
            sys_get_temp_dir()
        );
    }

    public function testGenerateMigrationForNewTable(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $this->connectionManager->method('executeQuery')->willReturn($stmt);

        $column = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: 'int',
            isId: true
        );
        $meta = new ClassMetadata(
            entityClass: \stdClass::class,
            tableName: 'users',
            columns: [$column]
        );

        $this->metadataReader->method('getClassMetadata')->willReturn($meta);

        // When table doesn't exist, it should generate CREATE TABLE
        $reflection = new \ReflectionMethod(MigrationManager::class, 'generateCreateTableSQL');
        $reflection->setAccessible(true);
        $sql = $reflection->invoke($this->migrationManager, $meta);

        $this->assertStringContainsString('CREATE TABLE [users]', $sql);
        $this->assertStringContainsString('[id] INT NOT NULL', $sql);
    }
}
