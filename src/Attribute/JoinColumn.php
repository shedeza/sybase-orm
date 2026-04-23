<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Specifies the join column for a relationship.
 *
 * Defines the foreign key column name and the referenced column
 * on the target entity's table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class JoinColumn
{
    public function __construct(
        public readonly string $name,
        public readonly string $referencedColumnName = 'id',
    ) {
    }
}
