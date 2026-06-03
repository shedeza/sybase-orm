<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a logical expression combining two conditions with AND/OR.
 */
final class LogicalExpression
{
    public function __construct(
        public readonly Comparison|LogicalExpression|IsNullExpression|InExpression $left,
        public readonly string $operator,
        public readonly Comparison|LogicalExpression|IsNullExpression|InExpression $right,
    ) {}
}
