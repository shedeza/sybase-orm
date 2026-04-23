<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a comparison expression: left operator right
 * e.g. u.name = :name, u.age > 18
 */
final class Comparison
{
    public function __construct(
        public readonly PropertyAccess|Literal|Parameter|FunctionCall $left,
        public readonly string $operator,
        public readonly PropertyAccess|Literal|Parameter|FunctionCall $right,
    ) {
    }
}
