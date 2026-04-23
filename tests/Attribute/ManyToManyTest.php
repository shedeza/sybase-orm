<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\ManyToMany;

final class ManyToManyTest extends TestCase
{
    public function testDefaults(): void
    {
        $attr = new ManyToMany(targetEntity: 'App\\Entity\\Role');
        $this->assertSame('App\\Entity\\Role', $attr->targetEntity);
        $this->assertNull($attr->mappedBy);
        $this->assertNull($attr->inversedBy);
        $this->assertNull($attr->joinTable);
        $this->assertSame([], $attr->cascade);
        $this->assertSame('LAZY', $attr->fetch);
    }

    public function testOwningSide(): void
    {
        $attr = new ManyToMany(
            targetEntity: 'App\\Entity\\Role',
            inversedBy: 'users',
            joinTable: 'user_roles',
            cascade: ['persist', 'remove'],
        );

        $this->assertSame('App\\Entity\\Role', $attr->targetEntity);
        $this->assertNull($attr->mappedBy);
        $this->assertSame('users', $attr->inversedBy);
        $this->assertSame('user_roles', $attr->joinTable);
        $this->assertSame(['persist', 'remove'], $attr->cascade);
        $this->assertSame('LAZY', $attr->fetch);
    }

    public function testInverseSide(): void
    {
        $attr = new ManyToMany(
            targetEntity: 'App\\Entity\\User',
            mappedBy: 'roles',
            fetch: 'EAGER',
        );

        $this->assertSame('App\\Entity\\User', $attr->targetEntity);
        $this->assertSame('roles', $attr->mappedBy);
        $this->assertNull($attr->inversedBy);
        $this->assertNull($attr->joinTable);
        $this->assertSame([], $attr->cascade);
        $this->assertSame('EAGER', $attr->fetch);
    }

    public function testIsTargetProperty(): void
    {
        $ref = new \ReflectionClass(ManyToMany::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_PROPERTY, $attribute->flags);
    }

    public function testAttributeOnProperty(): void
    {
        $ref = new \ReflectionClass(Fixtures\UserEntity::class);
        $prop = $ref->getProperty('roles');
        $attrs = $prop->getAttributes(ManyToMany::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame(Fixtures\RoleEntity::class, $instance->targetEntity);
        $this->assertSame('users', $instance->inversedBy);
        $this->assertSame('user_roles', $instance->joinTable);
        $this->assertSame(['persist', 'remove'], $instance->cascade);
        $this->assertSame('LAZY', $instance->fetch);
    }
}
