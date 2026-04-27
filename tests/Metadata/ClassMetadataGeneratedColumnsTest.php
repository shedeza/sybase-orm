<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;

/**
 * Tests for ClassMetadata getGeneratedColumns().
 */
final class ClassMetadataGeneratedColumnsTest extends TestCase
{
    public function testGetGeneratedColumns(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true, generatedValue: 'IDENTITY'),
                new ColumnMetadata(propertyName: 'name', columnName: 'name', type: 'string'),
                new ColumnMetadata(propertyName: 'email', columnName: 'email', type: 'string'),
            ],
            idFields: ['id'],
        );

        $generated = $meta->getGeneratedColumns();

        $this->assertCount(1, $generated);
        $this->assertSame('id', $generated[0]->propertyName);
        $this->assertTrue($generated[0]->isGenerated());
    }

    public function testGetGeneratedColumnsReturnsEmptyWhenNone(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\Log',
            tableName: 'logs',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true),
                new ColumnMetadata(propertyName: 'message', columnName: 'message', type: 'string'),
            ],
            idFields: ['id'],
        );

        $this->assertCount(0, $meta->getGeneratedColumns());
    }
}
