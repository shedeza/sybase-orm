<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\RelationshipMetadata;

/**
 * Tests for ClassMetadata::getRelationshipByJoinColumn().
 */
final class ClassMetadataJoinColumnTest extends TestCase
{
    public function testGetRelationshipByJoinColumn(): void
    {
        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\Order',
            tableName: 'orders',
            relationships: [
                new RelationshipMetadata(
                    propertyName: 'user',
                    type: 'ManyToOne',
                    targetEntity: 'App\\Entity\\User',
                    joinColumn: 'user_id',
                    referencedColumnName: 'id',
                ),
                new RelationshipMetadata(
                    propertyName: 'product',
                    type: 'ManyToOne',
                    targetEntity: 'App\\Entity\\Product',
                    joinColumn: 'product_id',
                    referencedColumnName: 'id',
                ),
            ],
        );

        $rel = $meta->getRelationshipByJoinColumn('user_id');
        $this->assertNotNull($rel);
        $this->assertSame('user', $rel->propertyName);

        $rel2 = $meta->getRelationshipByJoinColumn('product_id');
        $this->assertNotNull($rel2);
        $this->assertSame('product', $rel2->propertyName);

        $this->assertNull($meta->getRelationshipByJoinColumn('nonexistent'));
    }
}
