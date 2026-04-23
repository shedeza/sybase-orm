<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\GeneratedValue;

final class GeneratedValueTest extends TestCase
{
    public function testDefaultStrategyIsIdentity(): void
    {
        $gv = new GeneratedValue();
        $this->assertSame('IDENTITY', $gv->strategy);
    }

    public function testCustomStrategy(): void
    {
        $gv = new GeneratedValue(strategy: 'SEQUENCE');
        $this->assertSame('SEQUENCE', $gv->strategy);
    }

    public function testIsTargetProperty(): void
    {
        $ref = new \ReflectionClass(GeneratedValue::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_PROPERTY, $attribute->flags);
    }

    public function testAttributeOnProperty(): void
    {
        $ref = new \ReflectionClass(Fixtures\SampleEntity::class);
        $prop = $ref->getProperty('id');
        $attrs = $prop->getAttributes(GeneratedValue::class);
        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame('IDENTITY', $instance->strategy);
    }
}
