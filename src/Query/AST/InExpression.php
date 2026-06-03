<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents an IN or NOT IN condition.
 * e.g. u.status IN (:statuses), u.id NOT IN (1, 2, 3)
 *
 * @property array<Parameter|Literal> $values
 */
final class InExpression
{
    /**
     * @param array<Parameter|Literal> $values Single Parameter or list of Literals
     */
    public function __construct(
        public readonly PropertyAccess $property,
        public readonly array $values,
        public readonly bool $negated = false,
    ) {
    }
}
