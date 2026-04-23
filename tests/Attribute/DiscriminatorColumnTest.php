<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\DiscriminatorColumn;

final class DiscriminatorColumnTest extends TestCase
{
    public function testNameAndDefaultType(): void
    {
        $attr = new DiscriminatorColumn(name: 'dtype');
        $this->assertSame('dtype', $attr->name);
        $this->assertSame('string', $attr->type);
    }

    public function testCustomType(): void
    {
        $attr = new DiscriminatorColumn(name: 'type_id', type: 'integer');
        $this->assertSame('type_id', $attr->name);
        $this->assertSame('integer', $attr->type);
    }

    public function testIsTargetClass(): void
    {
        $ref = new \ReflectionClass(DiscriminatorColumn::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $attribute->flags);
    }

    public function testAttributeOnClass(): void
    {
        $ref = new \ReflectionClass(Fixtures\AnimalEntity::class);
        $attrs = $ref->getAttributes(DiscriminatorColumn::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame('animal_type', $instance->name);
        $this->assertSame('string', $instance->type);
    }
}
