<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\JoinColumn;

final class JoinColumnTest extends TestCase
{
    public function testDefaults(): void
    {
        $attr = new JoinColumn(name: 'profile_id');
        $this->assertSame('profile_id', $attr->name);
        $this->assertSame('id', $attr->referencedColumnName);
    }

    public function testCustomParameters(): void
    {
        $attr = new JoinColumn(
            name: 'dept_id',
            referencedColumnName: 'department_id',
        );

        $this->assertSame('dept_id', $attr->name);
        $this->assertSame('department_id', $attr->referencedColumnName);
    }

    public function testIsTargetProperty(): void
    {
        $ref = new \ReflectionClass(JoinColumn::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_PROPERTY, $attribute->flags);
    }

    public function testAttributeOnProperty(): void
    {
        $ref = new \ReflectionClass(Fixtures\UserEntity::class);
        $prop = $ref->getProperty('profile');
        $attrs = $prop->getAttributes(JoinColumn::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame('profile_id', $instance->name);
        $this->assertSame('id', $instance->referencedColumnName);
    }
}
