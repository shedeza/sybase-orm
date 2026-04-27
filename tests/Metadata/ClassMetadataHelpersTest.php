<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;

/**
 * Tests for ClassMetadata helper methods: hasLifecycleHooks, hasInheritance, hasCompositeId.
 */
final class ClassMetadataHelpersTest extends TestCase
{
    public function testHasLifecycleHooksReturnsFalseWhenEmpty(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            lifecycleHooks: [],
        );

        $this->assertFalse($meta->hasLifecycleHooks());
    }

    public function testHasLifecycleHooksReturnsTrueWhenPresent(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            lifecycleHooks: ['PrePersist' => ['beforeCreate']],
        );

        $this->assertTrue($meta->hasLifecycleHooks());
    }

    public function testHasInheritanceReturnsFalseByDefault(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
        );

        $this->assertFalse($meta->hasInheritance());
    }

    public function testHasInheritanceReturnsTrueWhenSet(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\Vehicle',
            tableName: 'vehicles',
            inheritanceType: 'TPH',
        );

        $this->assertTrue($meta->hasInheritance());
    }

    public function testHasCompositeIdReturnsFalseForSingleId(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true),
            ],
            idFields: ['id'],
        );

        $this->assertFalse($meta->hasCompositeId());
    }

    public function testHasCompositeIdReturnsTrueForMultipleIds(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\Enrollment',
            tableName: 'enrollments',
            columns: [
                new ColumnMetadata(propertyName: 'studentId', columnName: 'student_id', type: 'integer', isId: true),
                new ColumnMetadata(propertyName: 'courseId', columnName: 'course_id', type: 'integer', isId: true),
            ],
            idFields: ['studentId', 'courseId'],
        );

        $this->assertTrue($meta->hasCompositeId());
    }

    public function testHasCompositeIdReturnsFalseForNoId(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\Log',
            tableName: 'logs',
        );

        $this->assertFalse($meta->hasCompositeId());
    }
}
