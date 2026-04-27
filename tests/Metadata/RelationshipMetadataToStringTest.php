<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\RelationshipMetadata;

/**
 * Tests for RelationshipMetadata::__toString().
 */
final class RelationshipMetadataToStringTest extends TestCase
{
    public function testToStringOneToMany(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'orders',
            type: 'OneToMany',
            targetEntity: 'App\\Entity\\Order',
        );

        $this->assertSame('OneToMany orders → App\\Entity\\Order', (string) $rel);
    }

    public function testToStringManyToOne(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'user',
            type: 'ManyToOne',
            targetEntity: 'App\\Entity\\User',
        );

        $this->assertSame('ManyToOne user → App\\Entity\\User', (string) $rel);
    }
}
