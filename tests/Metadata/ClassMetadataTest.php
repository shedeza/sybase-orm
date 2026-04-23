<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Metadata\RelationshipMetadata;

final class ClassMetadataTest extends TestCase
{
    public function testColumnMetadataDefaults(): void
    {
        $col = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
        );

        $this->assertSame('name', $col->propertyName);
        $this->assertSame('name', $col->columnName);
        $this->assertSame('string', $col->type);
        $this->assertFalse($col->nullable);
        $this->assertNull($col->length);
        $this->assertNull($col->precision);
        $this->assertNull($col->scale);
        $this->assertFalse($col->isId);
        $this->assertNull($col->generatedValue);
    }

    public function testColumnMetadataFullySpecified(): void
    {
        $col = new ColumnMetadata(
            propertyName: 'price',
            columnName: 'unit_price',
            type: 'decimal',
            nullable: true,
            length: 10,
            precision: 8,
            scale: 2,
            isId: false,
            generatedValue: null,
        );

        $this->assertSame('price', $col->propertyName);
        $this->assertSame('unit_price', $col->columnName);
        $this->assertSame('decimal', $col->type);
        $this->assertTrue($col->nullable);
        $this->assertSame(10, $col->length);
        $this->assertSame(8, $col->precision);
        $this->assertSame(2, $col->scale);
    }

    public function testColumnMetadataAsId(): void
    {
        $col = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: 'int',
            isId: true,
            generatedValue: 'IDENTITY',
        );

        $this->assertTrue($col->isId);
        $this->assertSame('IDENTITY', $col->generatedValue);
    }

    public function testRelationshipMetadataDefaults(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'author',
            type: 'ManyToOne',
            targetEntity: 'App\\Entity\\User',
        );

        $this->assertSame('author', $rel->propertyName);
        $this->assertSame('ManyToOne', $rel->type);
        $this->assertSame('App\\Entity\\User', $rel->targetEntity);
        $this->assertNull($rel->mappedBy);
        $this->assertNull($rel->inversedBy);
        $this->assertNull($rel->joinColumn);
        $this->assertNull($rel->referencedColumnName);
        $this->assertNull($rel->joinTable);
        $this->assertSame([], $rel->cascade);
        $this->assertSame('LAZY', $rel->fetch);
    }

    public function testRelationshipMetadataFullySpecified(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'roles',
            type: 'ManyToMany',
            targetEntity: 'App\\Entity\\Role',
            mappedBy: null,
            inversedBy: 'users',
            joinColumn: null,
            referencedColumnName: null,
            joinTable: 'user_roles',
            cascade: ['persist', 'remove'],
            fetch: 'EAGER',
        );

        $this->assertSame('roles', $rel->propertyName);
        $this->assertSame('ManyToMany', $rel->type);
        $this->assertSame('App\\Entity\\Role', $rel->targetEntity);
        $this->assertSame('users', $rel->inversedBy);
        $this->assertSame('user_roles', $rel->joinTable);
        $this->assertSame(['persist', 'remove'], $rel->cascade);
        $this->assertSame('EAGER', $rel->fetch);
    }

    public function testClassMetadataConstruction(): void
    {
        $idCol = new ColumnMetadata('id', 'id', 'int', false, null, null, null, true, 'IDENTITY');
        $nameCol = new ColumnMetadata('name', 'name', 'string', false, 255);

        $rel = new RelationshipMetadata(
            propertyName: 'posts',
            type: 'OneToMany',
            targetEntity: 'App\\Entity\\Post',
            mappedBy: 'author',
        );

        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [$idCol, $nameCol],
            idField: 'id',
            relationships: [$rel],
            inheritanceType: null,
            discriminatorColumn: null,
            discriminatorMap: [],
            lifecycleHooks: ['PrePersist' => ['setCreatedAt']],
        );

        $this->assertSame('App\\Entity\\User', $meta->entityClass);
        $this->assertSame('users', $meta->tableName);
        $this->assertCount(2, $meta->columns);
        $this->assertSame('id', $meta->idField);
        $this->assertCount(1, $meta->relationships);
        $this->assertNull($meta->inheritanceType);
        $this->assertNull($meta->discriminatorColumn);
        $this->assertSame([], $meta->discriminatorMap);
        $this->assertSame(['PrePersist' => ['setCreatedAt']], $meta->lifecycleHooks);
    }

    public function testGetColumnReturnsMatchingColumn(): void
    {
        $idCol = new ColumnMetadata('id', 'id', 'int');
        $nameCol = new ColumnMetadata('name', 'user_name', 'string');

        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [$idCol, $nameCol],
        );

        $found = $meta->getColumn('name');
        $this->assertNotNull($found);
        $this->assertSame('user_name', $found->columnName);
    }

    public function testGetColumnReturnsNullForUnknownProperty(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [new ColumnMetadata('id', 'id', 'int')],
        );

        $this->assertNull($meta->getColumn('nonexistent'));
    }

    public function testGetIdColumnReturnsIdColumn(): void
    {
        $idCol = new ColumnMetadata('id', 'id', 'int', false, null, null, null, true, 'IDENTITY');
        $nameCol = new ColumnMetadata('name', 'name', 'string');

        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [$idCol, $nameCol],
            idField: 'id',
        );

        $found = $meta->getIdColumn();
        $this->assertNotNull($found);
        $this->assertSame('id', $found->propertyName);
        $this->assertTrue($found->isId);
    }

    public function testGetIdColumnReturnsNullWhenNoIdField(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [new ColumnMetadata('name', 'name', 'string')],
        );

        $this->assertNull($meta->getIdColumn());
    }

    public function testGetRelationshipReturnsMatchingRelationship(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'profile',
            type: 'OneToOne',
            targetEntity: 'App\\Entity\\Profile',
            inversedBy: 'user',
        );

        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            relationships: [$rel],
        );

        $found = $meta->getRelationship('profile');
        $this->assertNotNull($found);
        $this->assertSame('OneToOne', $found->type);
    }

    public function testGetRelationshipReturnsNullForUnknownProperty(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
        );

        $this->assertNull($meta->getRelationship('nonexistent'));
    }

    public function testClassMetadataWithInheritance(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\Animal',
            tableName: 'animals',
            columns: [
                new ColumnMetadata('id', 'id', 'int', false, null, null, null, true),
                new ColumnMetadata('name', 'name', 'string'),
            ],
            idField: 'id',
            inheritanceType: 'TPH',
            discriminatorColumn: 'type',
            discriminatorMap: ['dog' => 'App\\Entity\\Dog', 'cat' => 'App\\Entity\\Cat'],
        );

        $this->assertSame('TPH', $meta->inheritanceType);
        $this->assertSame('type', $meta->discriminatorColumn);
        $this->assertSame(['dog' => 'App\\Entity\\Dog', 'cat' => 'App\\Entity\\Cat'], $meta->discriminatorMap);
    }

    public function testClassMetadataDefaults(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\Simple',
            tableName: 'simple',
        );

        $this->assertSame([], $meta->columns);
        $this->assertNull($meta->idField);
        $this->assertSame([], $meta->relationships);
        $this->assertNull($meta->inheritanceType);
        $this->assertNull($meta->discriminatorColumn);
        $this->assertSame([], $meta->discriminatorMap);
        $this->assertSame([], $meta->lifecycleHooks);
    }
}
