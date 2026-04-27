<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\RelationshipMetadata;

/**
 * Tests for RelationshipMetadata isOwningSide() and isInverseSide().
 */
final class RelationshipMetadataOwningSideTest extends TestCase
{
    public function testOwningSideWhenMappedByIsNull(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'user',
            type: 'ManyToOne',
            targetEntity: 'App\\Entity\\User',
            inversedBy: 'orders',
        );

        $this->assertTrue($rel->isOwningSide());
        $this->assertFalse($rel->isInverseSide());
    }

    public function testInverseSideWhenMappedByIsSet(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'orders',
            type: 'OneToMany',
            targetEntity: 'App\\Entity\\Order',
            mappedBy: 'user',
        );

        $this->assertFalse($rel->isOwningSide());
        $this->assertTrue($rel->isInverseSide());
    }
}
