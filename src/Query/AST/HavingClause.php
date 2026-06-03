<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents the HAVING clause containing a condition expression.
 */
final class HavingClause
{
    public function __construct(
        public readonly Comparison|LogicalExpression|IsNullExpression|InExpression $condition,
    ) {}
}
