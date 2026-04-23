<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Entity;

final class EntityTest extends TestCase
{
    public function testDefaultTableIsNull(): void
    {
        $entity = new Entity();
        $this->assertNull($entity->table);
    }

    public function testCustomTableName(): void
    {
        $entity = new Entity(table: 'custom_table');
        $this->assertSame('custom_table', $entity->table);
    }

    public function testIsTargetClass(): void
    {
        $ref = new \ReflectionClass(Entity::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $attribute->flags);
    }

    public function testAttributeOnClass(): void
    {
        $ref = new \ReflectionClass(Fixtures\SampleEntity::class);
        $attrs = $ref->getAttributes(Entity::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame('my_table', $instance->table);
    }

    public function testAttributeOnClassWithoutTable(): void
    {
        $ref = new \ReflectionClass(Fixtures\SampleEntityNoTable::class);
        $attrs = $ref->getAttributes(Entity::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertNull($instance->table);
    }
}
