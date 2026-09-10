<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Migration;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\Migration\MigrationManager;
use SybaseORM\Tests\ORM\TestEmailNotification;
use SybaseORM\Tests\ORM\TestNotification;
use SybaseORM\Tests\ORM\TestSmsNotification;

final class MigrationTphDdlTest extends TestCase
{
    public function testPreviewDeduplicatesSharedTablesAndIncludesDiscriminator(): void
    {
        $connection = $this->createMock(ConnectionManagerInterface::class);
        $dialect = new SybaseDialect();
        $metadataReader = new MetadataReader();

        // Simulate table does not exist
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $connection->method('executeQuery')->willReturn($stmt);

        $migrationManager = new MigrationManager($connection, $metadataReader, $dialect, sys_get_temp_dir());

        $preview = $migrationManager->preview([
            TestNotification::class,
            TestEmailNotification::class,
            TestSmsNotification::class,
        ]);

        $this->assertArrayHasKey('up', $preview);
        // Table 'test_notifications' should only have 1 CREATE TABLE statement, not 3
        $createTableCount = 0;
        foreach ($preview['up'] as $sql) {
            if (str_contains($sql, 'CREATE TABLE [test_notifications]')) {
                $createTableCount++;
                // Must contain discriminator column 'type'
                $this->assertStringContainsString('[type]', $sql);
                // Must contain subclass columns like 'email' and 'phone'
                $this->assertStringContainsString('[email]', $sql);
                $this->assertStringContainsString('[phone_number]', $sql);
            }
        }

        $this->assertSame(1, $createTableCount, 'Expected exactly one CREATE TABLE for shared TPH hierarchy table.');
    }
}
