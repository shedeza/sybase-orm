<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\Tests\Attribute\Fixtures\AnimalEntity;
use SybaseORM\Tests\Attribute\Fixtures\AuditableEntity;
use SybaseORM\Tests\Attribute\Fixtures\SampleEntity;
use SybaseORM\Tests\Attribute\Fixtures\SampleEntityNoTable;
use SybaseORM\Tests\Attribute\Fixtures\SchemaEntity;
use SybaseORM\Tests\Attribute\Fixtures\UserEntity;
use SybaseORM\Tests\Attribute\Fixtures\VehicleEntity;
use SybaseORM\Tests\Metadata\Fixtures\CompositeKeyEntity;
use SybaseORM\Tests\Metadata\Fixtures\SingleKeyEntity;

final class MetadataReaderTest extends TestCase
{
    private MetadataReader $reader;

    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();
        $this->reader = new MetadataReader();
    }

    // --- isEntity ---

    public function testIsEntityReturnsTrueForEntityClass(): void
    {
        $this->assertTrue($this->reader->isEntity(SampleEntity::class));
    }

    public function testIsEntityReturnsFalseForNonEntityClass(): void
    {
        $this->assertFalse($this->reader->isEntity(\stdClass::class));
    }

    public function testIsEntityReturnsFalseForNonExistentClass(): void
    {
        $this->assertFalse($this->reader->isEntity('NonExistent\\ClassName'));
    }

    // --- Table name ---

    public function testExplicitTableName(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntity::class);
        $this->assertSame('my_table', $meta->tableName);
    }

    public function testSnakeCaseTableNameWhenNotSpecified(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntityNoTable::class);
        $this->assertSame('sample_entity_no_table', $meta->tableName);
    }

    // --- Columns ---

    public function testReadsColumnsWithExplicitName(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntity::class);
        $nameCol = $meta->getColumn('name');

        $this->assertNotNull($nameCol);
        $this->assertSame('user_name', $nameCol->columnName);
        $this->assertSame('varchar', $nameCol->type);
        $this->assertSame(100, $nameCol->length);
        $this->assertFalse($nameCol->nullable);
    }

    public function testSnakeCaseColumnNameWhenNotSpecified(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntity::class);
        $balanceCol = $meta->getColumn('balance');

        $this->assertNotNull($balanceCol);
        $this->assertSame('balance', $balanceCol->columnName);
    }

    public function testColumnNullableAndPrecision(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntity::class);
        $balanceCol = $meta->getColumn('balance');

        $this->assertNotNull($balanceCol);
        $this->assertTrue($balanceCol->nullable);
        $this->assertSame(10, $balanceCol->precision);
        $this->assertSame(2, $balanceCol->scale);
        $this->assertSame('decimal', $balanceCol->type);
    }

    // --- Id and GeneratedValue ---

    public function testReadsIdField(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntity::class);
        $this->assertSame('id', $meta->idField);
    }

    public function testIdColumnIsMarked(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntity::class);
        $idCol = $meta->getIdColumn();

        $this->assertNotNull($idCol);
        $this->assertTrue($idCol->isId);
        $this->assertSame('integer', $idCol->type);
    }

    public function testGeneratedValueStrategy(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntity::class);
        $idCol = $meta->getIdColumn();

        $this->assertNotNull($idCol);
        $this->assertSame('IDENTITY', $idCol->generatedValue);
    }

    public function testIdWithoutGeneratedValue(): void
    {
        $meta = $this->reader->getClassMetadata(UserEntity::class);
        $idCol = $meta->getIdColumn();

        $this->assertNotNull($idCol);
        $this->assertTrue($idCol->isId);
        $this->assertNull($idCol->generatedValue);
    }

    // --- Composite key reading ---

    public function testCompositeKeyEntityHasBothIdFields(): void
    {
        $meta = $this->reader->getClassMetadata(CompositeKeyEntity::class);

        $this->assertCount(2, $meta->idFields);
        $this->assertContains('orgId', $meta->idFields);
        $this->assertContains('userId', $meta->idFields);
    }

    public function testCompositeKeyEntityIdFieldPointsToFirst(): void
    {
        $meta = $this->reader->getClassMetadata(CompositeKeyEntity::class);

        $this->assertSame('orgId', $meta->idField);
    }

    public function testCompositeKeyEntityGetIdColumnsReturnsBothColumns(): void
    {
        $meta = $this->reader->getClassMetadata(CompositeKeyEntity::class);
        $idColumns = $meta->getIdColumns();

        $this->assertCount(2, $idColumns);
        $propertyNames = array_map(fn($col) => $col->propertyName, $idColumns);
        $this->assertContains('orgId', $propertyNames);
        $this->assertContains('userId', $propertyNames);
    }

    public function testSingleKeyEntityIdFieldsIsSingleElementArray(): void
    {
        $meta = $this->reader->getClassMetadata(SingleKeyEntity::class);

        $this->assertCount(1, $meta->idFields);
        $this->assertSame('id', $meta->idFields[0]);
    }

    public function testSingleKeyEntityIdFieldUnchanged(): void
    {
        $meta = $this->reader->getClassMetadata(SingleKeyEntity::class);

        $this->assertSame('id', $meta->idField);
    }

    public function testSingleKeyEntityGetIdColumnUnchanged(): void
    {
        $meta = $this->reader->getClassMetadata(SingleKeyEntity::class);
        $idCol = $meta->getIdColumn();

        $this->assertNotNull($idCol);
        $this->assertSame('id', $idCol->propertyName);
        $this->assertTrue($idCol->isId);
    }

    // --- Relationships ---

    public function testReadsOneToOneRelationship(): void
    {
        $meta = $this->reader->getClassMetadata(UserEntity::class);
        $rel = $meta->getRelationship('profile');

        $this->assertNotNull($rel);
        $this->assertSame('OneToOne', $rel->type);
        $this->assertStringContainsString('ProfileEntity', $rel->targetEntity);
        $this->assertSame('user', $rel->inversedBy);
        $this->assertNull($rel->mappedBy);
        $this->assertSame('profile_id', $rel->joinColumn);
        $this->assertSame('id', $rel->referencedColumnName);
        $this->assertSame(['persist', 'remove'], $rel->cascade);
        $this->assertSame('EAGER', $rel->fetch);
    }

    public function testReadsOneToManyRelationship(): void
    {
        $meta = $this->reader->getClassMetadata(UserEntity::class);
        $rel = $meta->getRelationship('posts');

        $this->assertNotNull($rel);
        $this->assertSame('OneToMany', $rel->type);
        $this->assertSame('author', $rel->mappedBy);
        $this->assertNull($rel->joinColumn);
        $this->assertSame(['persist'], $rel->cascade);
        $this->assertSame('LAZY', $rel->fetch);
    }

    public function testReadsManyToOneRelationship(): void
    {
        $meta = $this->reader->getClassMetadata(UserEntity::class);
        $rel = $meta->getRelationship('department');

        $this->assertNotNull($rel);
        $this->assertSame('ManyToOne', $rel->type);
        $this->assertSame('employees', $rel->inversedBy);
        $this->assertSame('department_id', $rel->joinColumn);
        $this->assertSame('EAGER', $rel->fetch);
    }

    public function testReadsManyToManyRelationship(): void
    {
        $meta = $this->reader->getClassMetadata(UserEntity::class);
        $rel = $meta->getRelationship('roles');

        $this->assertNotNull($rel);
        $this->assertSame('ManyToMany', $rel->type);
        $this->assertSame('users', $rel->inversedBy);
        $this->assertSame('user_roles', $rel->joinTable);
        $this->assertSame(['persist', 'remove'], $rel->cascade);
    }

    // --- Inheritance ---

    public function testReadsTPHInheritance(): void
    {
        $meta = $this->reader->getClassMetadata(AnimalEntity::class);

        $this->assertSame('TPH', $meta->inheritanceType);
        $this->assertSame('animal_type', $meta->discriminatorColumn);
        $this->assertArrayHasKey('dog', $meta->discriminatorMap);
        $this->assertArrayHasKey('cat', $meta->discriminatorMap);
    }

    public function testReadsTPTInheritance(): void
    {
        $meta = $this->reader->getClassMetadata(VehicleEntity::class);

        $this->assertSame('TPT', $meta->inheritanceType);
        $this->assertNull($meta->discriminatorColumn);
        $this->assertEmpty($meta->discriminatorMap);
    }

    public function testNoInheritanceReturnsNull(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntity::class);

        $this->assertNull($meta->inheritanceType);
        $this->assertNull($meta->discriminatorColumn);
        $this->assertEmpty($meta->discriminatorMap);
    }

    // --- Lifecycle Hooks ---

    public function testReadsLifecycleHooks(): void
    {
        $meta = $this->reader->getClassMetadata(AuditableEntity::class);

        $this->assertArrayHasKey('PrePersist', $meta->lifecycleHooks);
        $this->assertArrayHasKey('PostPersist', $meta->lifecycleHooks);
        $this->assertArrayHasKey('PreUpdate', $meta->lifecycleHooks);
        $this->assertArrayHasKey('PostUpdate', $meta->lifecycleHooks);
        $this->assertArrayHasKey('PreRemove', $meta->lifecycleHooks);
        $this->assertArrayHasKey('PostRemove', $meta->lifecycleHooks);

        $this->assertContains('onPrePersist', $meta->lifecycleHooks['PrePersist']);
        $this->assertContains('onPostPersist', $meta->lifecycleHooks['PostPersist']);
    }

    public function testNoLifecycleHooksWithoutAttribute(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntity::class);

        $this->assertEmpty($meta->lifecycleHooks);
    }

    // --- Error handling ---

    public function testThrowsForNonEntityClass(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not annotated with #[Entity]');

        $this->reader->getClassMetadata(\stdClass::class);
    }

    // --- Entity class stored ---

    public function testEntityClassIsStored(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntity::class);
        $this->assertSame(SampleEntity::class, $meta->entityClass);
    }

    // --- snake_case conversion for camelCase properties ---

    public function testSnakeCaseConversionForMultiWordProperty(): void
    {
        $meta = $this->reader->getClassMetadata(MetadataReaderSnakeCaseFixture::class);
        $col = $meta->getColumn('firstName');

        $this->assertNotNull($col);
        $this->assertSame('first_name', $col->columnName);
    }

    public function testSnakeCaseConversionForMultiWordClassName(): void
    {
        $meta = $this->reader->getClassMetadata(MetadataReaderSnakeCaseFixture::class);
        $this->assertSame('metadata_reader_snake_case_fixture', $meta->tableName);
    }

    // --- In-memory cache ---

    public function testMemoryCacheReturnsSameInstance(): void
    {
        $meta1 = $this->reader->getClassMetadata(SampleEntity::class);
        $meta2 = $this->reader->getClassMetadata(SampleEntity::class);

        $this->assertSame($meta1, $meta2);
    }

    public function testClearMemoryCacheForcesFreshRead(): void
    {
        $meta1 = $this->reader->getClassMetadata(SampleEntity::class);
        MetadataReader::clearMemoryCache();
        $meta2 = $this->reader->getClassMetadata(SampleEntity::class);

        $this->assertNotSame($meta1, $meta2);
        $this->assertSame($meta1->tableName, $meta2->tableName);
    }

    // --- File-based cache ---

    public function testFileCacheStoresAndLoadsMetadata(): void
    {
        $cacheDir = sys_get_temp_dir() . '/sybase_orm_test_cache_' . uniqid();

        try {
            $reader = new MetadataReader($cacheDir);
            MetadataReader::clearMemoryCache();

            // First call: reads from reflection, writes to file cache
            $meta1 = $reader->getClassMetadata(SampleEntity::class);

            // Clear memory cache to force file cache read
            MetadataReader::clearMemoryCache();

            // Second call: should load from file cache
            $meta2 = $reader->getClassMetadata(SampleEntity::class);

            $this->assertEquals($meta1, $meta2);
            $this->assertSame($meta1->tableName, $meta2->tableName);
            $this->assertSame($meta1->entityClass, $meta2->entityClass);

            // Verify cache file exists
            $expectedFile = $cacheDir . '/' . str_replace('\\', '_', SampleEntity::class) . '.cache';
            $this->assertFileExists($expectedFile);
        } finally {
            $this->removeDirectory($cacheDir);
        }
    }

    public function testFileCacheCreatesDirectoryIfMissing(): void
    {
        $cacheDir = sys_get_temp_dir() . '/sybase_orm_test_cache_' . uniqid() . '/nested';

        try {
            $reader = new MetadataReader($cacheDir);
            MetadataReader::clearMemoryCache();

            $reader->getClassMetadata(SampleEntity::class);

            $this->assertDirectoryExists($cacheDir);
        } finally {
            $this->removeDirectory(dirname($cacheDir));
        }
    }

    public function testNoCacheFileWithoutCacheDir(): void
    {
        $reader = new MetadataReader();
        MetadataReader::clearMemoryCache();

        $reader->getClassMetadata(SampleEntity::class);

        // No file should be created anywhere — just verify no exception
        $this->assertTrue(true);
    }

    // --- Schema ---

    public function testReadsSchemaFromEntityAttribute(): void
    {
        $meta = $this->reader->getClassMetadata(SchemaEntity::class);

        $this->assertSame('invoices', $meta->tableName);
        $this->assertSame('billing', $meta->schema);
    }

    public function testGetQualifiedTableNameWithSchema(): void
    {
        $meta = $this->reader->getClassMetadata(SchemaEntity::class);

        $this->assertSame('billing.invoices', $meta->getQualifiedTableName());
    }

    public function testGetQualifiedTableNameWithoutSchema(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntity::class);

        $this->assertNull($meta->schema);
        $this->assertSame('my_table', $meta->getQualifiedTableName());
    }

    public function testSchemaIsNullByDefault(): void
    {
        $meta = $this->reader->getClassMetadata(SampleEntityNoTable::class);

        $this->assertNull($meta->schema);
    }

    // --- Inherited properties ---

    public function testChildEntityIncludesParentPrivateColumns(): void
    {
        $meta = $this->reader->getClassMetadata(\SybaseORM\Tests\Attribute\Fixtures\ChildEntity::class);

        // Columnas del padre (private)
        $idCol = $meta->getColumn('id');
        $this->assertNotNull($idCol, 'Child entity should include parent private id column');
        $this->assertTrue($idCol->isId);

        $nameCol = $meta->getColumn('name');
        $this->assertNotNull($nameCol, 'Child entity should include parent private name column');

        // Columna propia
        $descCol = $meta->getColumn('description');
        $this->assertNotNull($descCol, 'Child entity should include its own description column');

        // idField debe estar definido desde el padre
        $this->assertSame('id', $meta->idField);
    }

    public function testChildEntityHasCorrectColumnCount(): void
    {
        $meta = $this->reader->getClassMetadata(\SybaseORM\Tests\Attribute\Fixtures\ChildEntity::class);

        // 2 del padre (id, name) + 1 propia (description) = 3
        $this->assertCount(3, $meta->columns);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
