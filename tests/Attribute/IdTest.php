<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Id;

final class IdTest extends TestCase
{
    public function testDefaultStrategyIsIdentity(): void
    {
        $id = new Id();
        $this->assertSame('identity', $id->strategy);
    }

    public function testCustomStrategy(): void
    {
        $id = new Id(strategy: 'sequence');
        $this->assertSame('sequence', $id->strategy);
    }

    public function testIsTargetProperty(): void
    {
        $ref = new \ReflectionClass(Id::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_PROPERTY, $attribute->flags);
    }

    public function testAttributeOnProperty(): void
    {
        $ref = new \ReflectionClass(Fixtures\SampleEntity::class);
        $prop = $ref->getProperty('id');
        $attrs = $prop->getAttributes(Id::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame('identity', $instance->strategy);
    }
}
