<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\ManyToOne;

final class ManyToOneTest extends TestCase
{
    public function testDefaults(): void
    {
        $attr = new ManyToOne(targetEntity: 'App\\Entity\\Department');
        $this->assertSame('App\\Entity\\Department', $attr->targetEntity);
        $this->assertNull($attr->inversedBy);
        $this->assertSame([], $attr->cascade);
        $this->assertSame('LAZY', $attr->fetch);
    }

    public function testCustomParameters(): void
    {
        $attr = new ManyToOne(
            targetEntity: 'App\\Entity\\Department',
            inversedBy: 'employees',
            cascade: ['persist'],
            fetch: 'EAGER',
        );

        $this->assertSame('App\\Entity\\Department', $attr->targetEntity);
        $this->assertSame('employees', $attr->inversedBy);
        $this->assertSame(['persist'], $attr->cascade);
        $this->assertSame('EAGER', $attr->fetch);
    }

    public function testIsTargetProperty(): void
    {
        $ref = new \ReflectionClass(ManyToOne::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_PROPERTY, $attribute->flags);
    }

    public function testAttributeOnProperty(): void
    {
        $ref = new \ReflectionClass(Fixtures\UserEntity::class);
        $prop = $ref->getProperty('department');
        $attrs = $prop->getAttributes(ManyToOne::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame(Fixtures\DepartmentEntity::class, $instance->targetEntity);
        $this->assertSame('employees', $instance->inversedBy);
        $this->assertSame(['persist'], $instance->cascade);
        $this->assertSame('EAGER', $instance->fetch);
    }
}
