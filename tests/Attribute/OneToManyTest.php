<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\OneToMany;

final class OneToManyTest extends TestCase
{
    public function testDefaults(): void
    {
        $attr = new OneToMany(targetEntity: 'App\\Entity\\Post', mappedBy: 'author');
        $this->assertSame('App\\Entity\\Post', $attr->targetEntity);
        $this->assertSame('author', $attr->mappedBy);
        $this->assertSame([], $attr->cascade);
        $this->assertSame('LAZY', $attr->fetch);
    }

    public function testCustomParameters(): void
    {
        $attr = new OneToMany(
            targetEntity: 'App\\Entity\\Post',
            mappedBy: 'author',
            cascade: ['persist', 'remove'],
            fetch: 'EAGER',
        );

        $this->assertSame('App\\Entity\\Post', $attr->targetEntity);
        $this->assertSame('author', $attr->mappedBy);
        $this->assertSame(['persist', 'remove'], $attr->cascade);
        $this->assertSame('EAGER', $attr->fetch);
    }

    public function testIsTargetProperty(): void
    {
        $ref = new \ReflectionClass(OneToMany::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_PROPERTY, $attribute->flags);
    }

    public function testAttributeOnProperty(): void
    {
        $ref = new \ReflectionClass(Fixtures\UserEntity::class);
        $prop = $ref->getProperty('posts');
        $attrs = $prop->getAttributes(OneToMany::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame(Fixtures\PostEntity::class, $instance->targetEntity);
        $this->assertSame('author', $instance->mappedBy);
        $this->assertSame(['persist'], $instance->cascade);
        $this->assertSame('LAZY', $instance->fetch);
    }
}
