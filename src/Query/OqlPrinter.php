<?php

declare(strict_types=1);

namespace SybaseORM\Query;

use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\CustomFunctionCall;
use SybaseORM\Query\AST\DeleteStatement;
use SybaseORM\Query\AST\FromClause;
use SybaseORM\Query\AST\FunctionCall;
use SybaseORM\Query\AST\GroupByClause;
use SybaseORM\Query\AST\HavingClause;
use SybaseORM\Query\AST\InExpression;
use SybaseORM\Query\AST\IsNullExpression;
use SybaseORM\Query\AST\JoinClause;
use SybaseORM\Query\AST\Literal;
use SybaseORM\Query\AST\LogicalExpression;
use SybaseORM\Query\AST\OrderByClause;
use SybaseORM\Query\AST\OrderByItem;
use SybaseORM\Query\AST\Parameter;
use SybaseORM\Query\AST\PropertyAccess;
use SybaseORM\Query\AST\SelectExpression;
use SybaseORM\Query\AST\SelectStatement;
use SybaseORM\Query\AST\SetClause;
use SybaseORM\Query\AST\UpdateStatement;
use SybaseORM\Query\AST\WhereClause;

/**
 * Traverses an OQL AST and produces a valid OQL text representation.
 */
final class OqlPrinter
{
    public function print(SelectStatement|UpdateStatement|DeleteStatement $statement): string
    {
        if ($statement instanceof UpdateStatement) {
            return $this->printUpdateStatement($statement);
        }

        if ($statement instanceof DeleteStatement) {
            return $this->printDeleteStatement($statement);
        }

        return $this->printSelectStatement($statement);
    }

    private function printSelectStatement(SelectStatement $statement): string
    {
        $parts = [];

        $select = 'SELECT';
        if ($statement->distinct) {
            $select .= ' DISTINCT';
        }
        $select .= ' ' . $this->printSelectExpressions($statement->selectExpressions);
        $parts[] = $select;

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

        if ($statement->havingClause !== null) {
            $parts[] = $this->printHavingClause($statement->havingClause);
        }

        if ($statement->orderBy !== null) {
            $parts[] = 'ORDER BY ' . $this->printOrderByClause($statement->orderBy);
        }

        return implode(' ', $parts);
    }

    private function printUpdateStatement(UpdateStatement $statement): string
    {
        $parts = [];

        $parts[] = 'UPDATE ' . $statement->entityName . ' ' . $statement->alias;

        $setClauses = implode(', ', array_map(
            fn(SetClause $sc) => $this->printSetClause($sc),
            $statement->setClauses,
        ));
        $parts[] = 'SET ' . $setClauses;

        if ($statement->where !== null) {
            $parts[] = 'WHERE ' . $this->printCondition($statement->where->condition);
        }

        return implode(' ', $parts);
    }

    private function printDeleteStatement(DeleteStatement $statement): string
    {
        $parts = [];

        $parts[] = 'DELETE FROM ' . $statement->entityName . ' ' . $statement->alias;

        if ($statement->where !== null) {
            $parts[] = 'WHERE ' . $this->printCondition($statement->where->condition);
        }

        return implode(' ', $parts);
    }

    private function printSetClause(SetClause $clause): string
    {
        $property = $clause->property->alias . '.' . $clause->property->property;
        $value = $this->printSetValue($clause->value);

        return $property . ' = ' . $value;
    }

    private function printSetValue(Parameter|Literal|CustomFunctionCall $value): string
    {
        if ($value instanceof CustomFunctionCall) {
            return $this->printCustomFunctionCall($value);
        }

        if ($value instanceof Parameter) {
            return ':' . $value->name;
        }

        if ($value instanceof Literal) {
            if ($value->type === 'null') {
                return 'NULL';
            }
            if ($value->type === 'string') {
                return "'" . $value->value . "'";
            }
            return (string) $value->value;
        }

        return '';
    }

    private function printCustomFunctionCall(CustomFunctionCall $func): string
    {
        if ($func->functionName === 'RAND') {
            return 'RAND()';
        }

        // CONVERT(expr AS type)
        $args = array_map(
            fn(PropertyAccess|Literal|Parameter|CustomFunctionCall $arg) => $this->printCustomFunctionArgument($arg),
            $func->arguments,
        );

        return 'CONVERT(' . implode(', ', $args) . ' AS ' . $func->castType . ')';
    }

    private function printCustomFunctionArgument(PropertyAccess|Literal|Parameter|CustomFunctionCall $arg): string
    {
        if ($arg instanceof CustomFunctionCall) {
            return $this->printCustomFunctionCall($arg);
        }

        if ($arg instanceof PropertyAccess) {
            return $arg->alias . '.' . $arg->property;
        }

        if ($arg instanceof Parameter) {
            return ':' . $arg->name;
        }

        if ($arg instanceof Literal) {
            if ($arg->type === 'null') {
                return 'NULL';
            }
            if ($arg->type === 'string') {
                return "'" . $arg->value . "'";
            }
            return (string) $arg->value;
        }

        return '';
    }

    /**
     * @param SelectExpression[] $expressions
     */
    private function printSelectExpressions(array $expressions): string
    {
        return implode(', ', array_map(
            fn(SelectExpression $e) => $this->printSelectExpression($e),
            $expressions,
        ));
    }

    private function printSelectExpression(SelectExpression $expr): string
    {
        if ($expr->expression instanceof FunctionCall) {
            $printed = $this->printFunctionCall($expr->expression);
        } else {
            $printed = $expr->expression;
        }

        if ($expr->alias !== null) {
            return $printed . ' AS ' . $expr->alias;
        }

        return $printed;
    }

    private function printFromClause(FromClause $from): string
    {
        return $from->entityName . ' ' . $from->alias;
    }

    private function printJoinClause(JoinClause $join): string
    {
        // Entity-based join: JOIN EntityName alias WITH condition
        if ($join->entityName !== null) {
            $result = $join->joinType . ' ' . $join->entityName . ' ' . $join->alias;
            if ($join->withCondition !== null) {
                $result .= ' WITH ' . $this->printCondition($join->withCondition);
            }

            return $result;
        }

        // Relationship-based join: JOIN alias.property newAlias
        $property = $join->property->alias . '.' . $join->property->property;

        return $join->joinType . ' ' . $property . ' ' . $join->alias;
    }

    private function printCondition(Comparison|LogicalExpression|IsNullExpression|InExpression $condition): string
    {
        if ($condition instanceof IsNullExpression) {
            return $this->printIsNullExpression($condition);
        }

        if ($condition instanceof InExpression) {
            return $this->printInExpression($condition);
        }

        if ($condition instanceof Comparison) {
            return $this->printComparison($condition);
        }

        return $this->printLogicalExpression($condition);
    }

    private function printComparison(Comparison $comparison): string
    {
        $left = $this->printComparisonOperand($comparison->left);
        $right = $this->printComparisonOperand($comparison->right);

        return $left . ' ' . $comparison->operator . ' ' . $right;
    }

    private function printLogicalExpression(LogicalExpression $expr): string
    {
        $left = $this->printCondition($expr->left);
        $right = $this->printCondition($expr->right);

        return $left . ' ' . $expr->operator . ' ' . $right;
    }

    private function printIsNullExpression(IsNullExpression $expr): string
    {
        $property = $expr->property->alias . '.' . $expr->property->property;

        if ($expr->negated) {
            return $property . ' IS NOT NULL';
        }

        return $property . ' IS NULL';
    }

    private function printInExpression(InExpression $expr): string
    {
        $property = $expr->property->alias . '.' . $expr->property->property;

        $values = implode(', ', array_map(
            fn(Parameter|Literal $v) => $this->printOperand($v),
            $expr->values,
        ));

        if ($expr->negated) {
            return $property . ' NOT IN (' . $values . ')';
        }

        return $property . ' IN (' . $values . ')';
    }

    private function printFunctionCall(FunctionCall $fc): string
    {
        $argument = $fc->argument instanceof PropertyAccess
            ? $fc->argument->alias . '.' . $fc->argument->property
            : $fc->argument;

        if ($fc->distinct) {
            return $fc->functionName . '(DISTINCT ' . $argument . ')';
        }

        return $fc->functionName . '(' . $argument . ')';
    }

    private function printHavingClause(HavingClause $hc): string
    {
        return 'HAVING ' . $this->printCondition($hc->condition);
    }

    /**
     * Prints an operand that can appear in a Comparison (includes FunctionCall).
     */
    private function printComparisonOperand(PropertyAccess|Literal|Parameter|FunctionCall|CustomFunctionCall $operand): string
    {
        if ($operand instanceof FunctionCall) {
            return $this->printFunctionCall($operand);
        }

        if ($operand instanceof CustomFunctionCall) {
            return $this->printCustomFunctionCall($operand);
        }

        return $this->printOperand($operand);
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
