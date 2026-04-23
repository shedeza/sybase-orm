<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\InheritanceType;

final class InheritanceTypeTest extends TestCase
{
    public function testTphStrategy(): void
    {
        $attr = new InheritanceType(strategy: 'TPH');
        $this->assertSame('TPH', $attr->strategy);
    }

    public function testTptStrategy(): void
    {
        $attr = new InheritanceType(strategy: 'TPT');
        $this->assertSame('TPT', $attr->strategy);
    }

    public function testTpcStrategy(): void
    {
        $attr = new InheritanceType(strategy: 'TPC');
        $this->assertSame('TPC', $attr->strategy);
    }

    public function testIsTargetClass(): void
    {
        $ref = new \ReflectionClass(InheritanceType::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $attribute->flags);
    }

    public function testAttributeOnClass(): void
    {
        $ref = new \ReflectionClass(Fixtures\AnimalEntity::class);
        $attrs = $ref->getAttributes(InheritanceType::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame('TPH', $instance->strategy);
    }

    public function testAttributeOnTptClass(): void
    {
        $ref = new \ReflectionClass(Fixtures\VehicleEntity::class);
        $attrs = $ref->getAttributes(InheritanceType::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame('TPT', $instance->strategy);
    }
}
