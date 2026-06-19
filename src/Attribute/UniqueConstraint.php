<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Defines a unique constraint on one or more columns of an entity.
 *
 * Can be applied multiple times to define multiple unique constraints.
 *
 * Usage (single column):
 *     #[Entity(table: 'users')]
 *     #[UniqueConstraint(columns: ['email'])]
 *     class User { ... }
 *
 * Usage (composite unique):
 *     #[Entity(table: 'emp_plaza')]
 *     #[UniqueConstraint(columns: ['empleado_id', 'plaza_id'], name: 'uq_emp_plaza')]
 *     class EmpPlaza { ... }
 *
 * Usage (multiple constraints):
 *     #[UniqueConstraint(columns: ['email'])]
 *     #[UniqueConstraint(columns: ['username'])]
 *     class User { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class UniqueConstraint
{
    /**
     * @param string[] $columns Column names (database names, not property names)
     * @param string|null $name Constraint name (auto-generated if null)
     */
    public function __construct(
        public readonly array $columns,
        public readonly ?string $name = null,
    ) {}
}
