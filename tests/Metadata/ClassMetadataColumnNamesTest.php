<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Metadata\RelationshipMetadata;

/**
 * Tests for ClassMetadata getColumnPropertyNames, getColumnNames, getRelationshipsByType.
 */
final class ClassMetadataColumnNamesTest extends TestCase
{
    public function testGetColumnPropertyNames(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true),
                new ColumnMetadata(propertyName: 'name', columnName: 'user_name', type: 'string'),
                new ColumnMetadata(propertyName: 'email', columnName: 'email_address', type: 'string'),
            ],
            idFields: ['id'],
        );

        $this->assertSame(['id', 'name', 'email'], $meta->getColumnPropertyNames());
    }

    public function testGetColumnNames(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true),
                new ColumnMetadata(propertyName: 'name', columnName: 'user_name', type: 'string'),
            ],
            idFields: ['id'],
        );

        $this->assertSame(['id', 'user_name'], $meta->getColumnNames());
    }

    public function testGetRelationshipsByType(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            relationships: [
                new RelationshipMetadata(propertyName: 'orders', type: 'OneToMany', targetEntity: 'App\\Entity\\Order'),
                new RelationshipMetadata(propertyName: 'profile', type: 'OneToOne', targetEntity: 'App\\Entity\\Profile'),
                new RelationshipMetadata(propertyName: 'roles', type: 'ManyToMany', targetEntity: 'App\\Entity\\Role'),
            ],
        );

        $oneToMany = $meta->getRelationshipsByType('OneToMany');
        $this->assertCount(1, $oneToMany);
        $this->assertSame('orders', $oneToMany[0]->propertyName);

        $manyToMany = $meta->getRelationshipsByType('ManyToMany');
        $this->assertCount(1, $manyToMany);
        $this->assertSame('roles', $manyToMany[0]->propertyName);

        $manyToOne = $meta->getRelationshipsByType('ManyToOne');
        $this->assertCount(0, $manyToOne);
    }
}
