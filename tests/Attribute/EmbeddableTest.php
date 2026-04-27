<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Embeddable;
use SybaseORM\Attribute\Embedded;

/**
 * Tests for Embeddable and Embedded attributes.
 */
final class EmbeddableTest extends TestCase
{
    public function testEmbeddableAttributeExists(): void
    {
        $attr = new Embeddable();

        $this->assertInstanceOf(Embeddable::class, $attr);
    }

    public function testEmbeddedAttributeDefaults(): void
    {
        $attr = new Embedded(class: 'App\\ValueObject\\Address');

        $this->assertSame('App\\ValueObject\\Address', $attr->class);
        $this->assertNull($attr->columnPrefix);
    }

    public function testEmbeddedAttributeWithPrefix(): void
    {
        $attr = new Embedded(class: 'App\\ValueObject\\Address', columnPrefix: 'billing_');

        $this->assertSame('App\\ValueObject\\Address', $attr->class);
        $this->assertSame('billing_', $attr->columnPrefix);
    }
}
