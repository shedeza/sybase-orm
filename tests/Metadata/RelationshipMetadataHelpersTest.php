<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\RelationshipMetadata;

/**
 * Tests for RelationshipMetadata helper methods.
 */
final class RelationshipMetadataHelpersTest extends TestCase
{
    public function testHasCascadePersist(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'orders',
            type: 'OneToMany',
            targetEntity: 'App\\Entity\\Order',
            cascade: ['persist'],
        );

        $this->assertTrue($rel->hasCascadePersist());
        $this->assertFalse($rel->hasCascadeRemove());
    }

    public function testHasCascadeRemove(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'orders',
            type: 'OneToMany',
            targetEntity: 'App\\Entity\\Order',
            cascade: ['remove'],
        );

        $this->assertFalse($rel->hasCascadePersist());
        $this->assertTrue($rel->hasCascadeRemove());
    }

    public function testNoCascade(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'orders',
            type: 'OneToMany',
            targetEntity: 'App\\Entity\\Order',
        );

        $this->assertFalse($rel->hasCascadePersist());
        $this->assertFalse($rel->hasCascadeRemove());
    }

    public function testIsToOneForManyToOne(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'user',
            type: 'ManyToOne',
            targetEntity: 'App\\Entity\\User',
        );

        $this->assertTrue($rel->isToOne());
        $this->assertFalse($rel->isToMany());
    }

    public function testIsToOneForOneToOne(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'profile',
            type: 'OneToOne',
            targetEntity: 'App\\Entity\\Profile',
        );

        $this->assertTrue($rel->isToOne());
        $this->assertFalse($rel->isToMany());
    }

    public function testIsToManyForOneToMany(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'orders',
            type: 'OneToMany',
            targetEntity: 'App\\Entity\\Order',
        );

        $this->assertFalse($rel->isToOne());
        $this->assertTrue($rel->isToMany());
    }

    public function testIsToManyForManyToMany(): void
    {
        $rel = new RelationshipMetadata(
            propertyName: 'roles',
            type: 'ManyToMany',
            targetEntity: 'App\\Entity\\Role',
        );

        $this->assertFalse($rel->isToOne());
        $this->assertTrue($rel->isToMany());
    }
}
