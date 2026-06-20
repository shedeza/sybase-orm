<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Specifies the join column for a relationship.
 *
 * Defines the foreign key column name, the referenced column
 * on the target entity's table, and optional FK actions.
 *
 * onDelete/onUpdate control DATABASE-LEVEL referential actions (DDL).
 * The 'cascade' option on ManyToOne/OneToMany controls ORM-LEVEL cascading.
 *
 * Valid actions: 'CASCADE', 'SET NULL', 'SET DEFAULT', 'NO ACTION', 'RESTRICT'
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class JoinColumn
{
    public function __construct(
        public readonly string $name,
        public readonly string $referencedColumnName = 'id',
        public readonly ?string $onDelete = null,
        public readonly ?string $onUpdate = null,
        public readonly bool $nullable = true,
    ) {}
}
