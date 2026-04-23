<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\OneToOne;

final class OneToOneTest extends TestCase
{
    public function testDefaults(): void
    {
        $attr = new OneToOne(targetEntity: 'App\\Entity\\Profile');
        $this->assertSame('App\\Entity\\Profile', $attr->targetEntity);
        $this->assertNull($attr->mappedBy);
        $this->assertNull($attr->inversedBy);
        $this->assertSame([], $attr->cascade);
        $this->assertSame('LAZY', $attr->fetch);
    }

    public function testCustomParameters(): void
    {
        $attr = new OneToOne(
            targetEntity: 'App\\Entity\\Profile',
            mappedBy: 'user',
            cascade: ['persist', 'remove'],
            fetch: 'EAGER',
        );

        $this->assertSame('App\\Entity\\Profile', $attr->targetEntity);
        $this->assertSame('user', $attr->mappedBy);
        $this->assertNull($attr->inversedBy);
        $this->assertSame(['persist', 'remove'], $attr->cascade);
        $this->assertSame('EAGER', $attr->fetch);
    }

    public function testInversedBy(): void
    {
        $attr = new OneToOne(
            targetEntity: 'App\\Entity\\Profile',
            inversedBy: 'user',
        );

        $this->assertNull($attr->mappedBy);
        $this->assertSame('user', $attr->inversedBy);
    }

    public function testIsTargetProperty(): void
    {
        $ref = new \ReflectionClass(OneToOne::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_PROPERTY, $attribute->flags);
    }

    public function testAttributeOnProperty(): void
    {
        $ref = new \ReflectionClass(Fixtures\UserEntity::class);
        $prop = $ref->getProperty('profile');
        $attrs = $prop->getAttributes(OneToOne::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame(Fixtures\ProfileEntity::class, $instance->targetEntity);
        $this->assertSame('user', $instance->inversedBy);
        $this->assertSame(['persist', 'remove'], $instance->cascade);
        $this->assertSame('EAGER', $instance->fetch);
    }
}
