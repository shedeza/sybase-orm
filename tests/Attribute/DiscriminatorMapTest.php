<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\DiscriminatorMap;

final class DiscriminatorMapTest extends TestCase
{
    public function testMapValues(): void
    {
        $map = ['admin' => 'App\\Entity\\Admin', 'user' => 'App\\Entity\\User'];
        $attr = new DiscriminatorMap(map: $map);
        $this->assertSame($map, $attr->map);
    }

    public function testEmptyMap(): void
    {
        $attr = new DiscriminatorMap(map: []);
        $this->assertSame([], $attr->map);
    }

    public function testIsTargetClass(): void
    {
        $ref = new \ReflectionClass(DiscriminatorMap::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $attribute->flags);
    }

    public function testAttributeOnClass(): void
    {
        $ref = new \ReflectionClass(Fixtures\AnimalEntity::class);
        $attrs = $ref->getAttributes(DiscriminatorMap::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $expected = [
            'dog' => Fixtures\DogEntity::class,
            'cat' => Fixtures\CatEntity::class,
        ];
        $this->assertSame($expected, $instance->map);
    }
}
