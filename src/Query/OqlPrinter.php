<?php

declare(strict_types=1);

namespace SybaseORM\Query;

use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\FromClause;
use SybaseORM\Query\AST\GroupByClause;
use SybaseORM\Query\AST\JoinClause;
use SybaseORM\Query\AST\Literal;
use SybaseORM\Query\AST\LogicalExpression;
use SybaseORM\Query\AST\OrderByClause;
use SybaseORM\Query\AST\OrderByItem;
use SybaseORM\Query\AST\Parameter;
use SybaseORM\Query\AST\PropertyAccess;
use SybaseORM\Query\AST\SelectExpression;
use SybaseORM\Query\AST\SelectStatement;
use SybaseORM\Query\AST\WhereClause;

/**
 * Traverses an OQL AST and produces a valid OQL text representation.
 */
final class OqlPrinter
{
    public function print(SelectStatement $statement): string
    {
        $parts = [];

        $parts[] = 'SELECT ' . $this->printSelectExpressions($statement->selectExpressions);
        $parts[] = 'FROM ' . $this->printFromClause($statement->from);

        foreach ($statement->joins as $join) {
            $parts[] = $this->printJoinClause($join);
        }

        if ($statement->where !== null) {
            $parts[] = 'WHERE ' . $this->printCondition($statement->where->condition);
        }

        if ($statement->groupBy !== null) {
            $parts[] = 'GROUP BY ' . $this->printGroupByClause($statement->groupBy);
        }

        if ($statement->orderBy !== null) {
            $parts[] = 'ORDER BY ' . $this->printOrderByClause($statement->orderBy);
        }

        return implode(' ', $parts);
    }

    /**
     * @param SelectExpression[] $expressions
     */
    private function printSelectExpressions(array $expressions): string
    {
        return implode(', ', array_map(
            fn(SelectExpression $e) => $e->alias !== null
                ? $e->expression . ' AS ' . $e->alias
                : $e->expression,
            $expressions,
        ));
    }

    private function printFromClause(FromClause $from): string
    {
        return $from->entityName . ' ' . $from->alias;
    }

    private function printJoinClause(JoinClause $join): string
    {
        $property = $join->property->alias . '.' . $join->property->property;

        return $join->joinType . ' ' . $property . ' ' . $join->alias;
    }

    private function printCondition(Comparison|LogicalExpression $condition): string
    {
        if ($condition instanceof Comparison) {
            return $this->printComparison($condition);
        }

        return $this->printLogicalExpression($condition);
    }

    private function printComparison(Comparison $comparison): string
    {
        $left = $this->printOperand($comparison->left);
        $right = $this->printOperand($comparison->right);

        return $left . ' ' . $comparison->operator . ' ' . $right;
    }

    private function printLogicalExpression(LogicalExpression $expr): string
    {
        $left = $this->printCondition($expr->left);
        $right = $this->printCondition($expr->right);

        return $left . ' ' . $expr->operator . ' ' . $right;
    }

    private function printOperand(PropertyAccess|Literal|Parameter $operand): string
    {
        if ($operand instanceof PropertyAccess) {
            return $operand->alias . '.' . $operand->property;
        }

        if ($operand instanceof Parameter) {
            return ':' . $operand->name;
        }

        if ($operand instanceof Literal) {
            if ($operand->type === 'string') {
                return "'" . $operand->value . "'";
            }

            return (string) $operand->value;
        }

        return '';
    }

    private function printOrderByClause(OrderByClause $orderBy): string
    {
        return implode(', ', array_map(
            fn(OrderByItem $item) => $item->property->alias . '.' . $item->property->property . ' ' . $item->direction,
            $orderBy->items,
        ));
    }

    private function printGroupByClause(GroupByClause $groupBy): string
    {
        return implode(', ', array_map(
            fn(PropertyAccess $p) => $p->alias . '.' . $p->property,
            $groupBy->properties,
        ));
    }
}
