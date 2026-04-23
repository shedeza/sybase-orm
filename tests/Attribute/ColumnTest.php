<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Column;

final class ColumnTest extends TestCase
{
    public function testDefaults(): void
    {
        $col = new Column();
        $this->assertNull($col->name);
        $this->assertSame('string', $col->type);
        $this->assertFalse($col->nullable);
        $this->assertNull($col->length);
        $this->assertNull($col->precision);
        $this->assertNull($col->scale);
    }

    public function testCustomParameters(): void
    {
        $col = new Column(
            name: 'user_name',
            type: 'varchar',
            nullable: true,
            length: 255,
            precision: 10,
            scale: 2,
        );

        $this->assertSame('user_name', $col->name);
        $this->assertSame('varchar', $col->type);
        $this->assertTrue($col->nullable);
        $this->assertSame(255, $col->length);
        $this->assertSame(10, $col->precision);
        $this->assertSame(2, $col->scale);
    }

    public function testIsTargetProperty(): void
    {
        $ref = new \ReflectionClass(Column::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_PROPERTY, $attribute->flags);
    }

    public function testAttributeOnProperty(): void
    {
        $ref = new \ReflectionClass(Fixtures\SampleEntity::class);
        $prop = $ref->getProperty('name');
        $attrs = $prop->getAttributes(Column::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame('user_name', $instance->name);
        $this->assertSame('varchar', $instance->type);
        $this->assertSame(100, $instance->length);
    }
}
