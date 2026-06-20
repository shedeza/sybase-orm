<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Defines a CHECK constraint on the entity's table.
 *
 * The expression is included in the DDL as-is. The ORM also validates
 * the expression in PHP before INSERT/UPDATE when possible.
 *
 * Usage:
 *     #[Entity(table: 'employees')]
 *     #[Check(expression: 'age >= 18', message: 'Employee must be at least 18.')]
 *     #[Check(expression: 'salary > 0')]
 *     class Employee { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Check
{
    public function __construct(
        /** SQL expression for the CHECK constraint */
        public readonly string $expression,
        /** Optional human-readable message for validation errors */
        public readonly ?string $message = null,
        /** Optional constraint name */
        public readonly ?string $name = null,
    ) {}
}
