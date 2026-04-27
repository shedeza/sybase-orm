<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;

/**
 * Tests for ClassMetadata getNullableColumns() and getNonIdColumns().
 */
final class ClassMetadataNullableColumnsTest extends TestCase
{
    private ClassMetadata $meta;

    protected function setUp(): void
    {
        $this->meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true),
                new ColumnMetadata(propertyName: 'name', columnName: 'name', type: 'string'),
                new ColumnMetadata(propertyName: 'email', columnName: 'email', type: 'string', nullable: true),
                new ColumnMetadata(propertyName: 'bio', columnName: 'bio', type: 'text', nullable: true),
            ],
            idFields: ['id'],
        );
    }

    public function testGetNullableColumns(): void
    {
        $nullable = $this->meta->getNullableColumns();

        $this->assertCount(2, $nullable);
        $this->assertSame('email', $nullable[0]->propertyName);
        $this->assertSame('bio', $nullable[1]->propertyName);
    }

    public function testGetNonIdColumns(): void
    {
        $nonId = $this->meta->getNonIdColumns();

        $this->assertCount(3, $nonId);
        $names = array_map(fn($c) => $c->propertyName, $nonId);
        $this->assertContains('name', $names);
        $this->assertContains('email', $names);
        $this->assertContains('bio', $names);
        $this->assertNotContains('id', $names);
    }
}
