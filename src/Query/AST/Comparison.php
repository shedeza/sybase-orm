<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a comparison expression: left operator right
 * e.g. u.name = :name, u.age > 18, CONVERT(u.value AS REAL) > 0.5
 */
final class Comparison
{
    public function __construct(
        public readonly PropertyAccess|Literal|Parameter|FunctionCall|CustomFunctionCall $left,
        public readonly string $operator,
        public readonly PropertyAccess|Literal|Parameter|FunctionCall|CustomFunctionCall $right,
    ) {}
}
