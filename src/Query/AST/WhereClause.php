<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents the WHERE clause containing a condition expression.
 */
final class WhereClause
{
    public function __construct(
        public readonly Comparison|LogicalExpression $condition,
    ) {
    }
}
